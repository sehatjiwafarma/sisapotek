<?php

/** Real HTTP smoke/regression run against a disposable QA tenant in the local application. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('RUN_LOCAL_E2E') !== '1' || ! $app->environment('local') || DB::connection()->getDatabaseName() !== 'apotek') {
    throw new RuntimeException('Live E2E requires explicit RUN_LOCAL_E2E=1, APP_ENV=local and database apotek. It never runs migrations or truncates tables.');
}
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\CashManagement;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

$base = 'http://127.0.0.1:8000';
$run = now('Asia/Jakarta')->format('Ymd-His').'-'.bin2hex(random_bytes(2));
$dir = storage_path('app/testing-reports/2026-11-11');
$report = ['run' => $run, 'started_at' => now('Asia/Jakarta')->toIso8601String(), 'transport' => 'Real HTTP, cookies, CSRF enabled', 'steps' => [], 'fixtures' => []];
$client = new Client(['base_uri' => $base, 'cookies' => new CookieJar, 'allow_redirects' => false, 'http_errors' => false, 'timeout' => 35]);
$token = null;
$owner = null;
$pharmacy = null;
$status = 1;
$http = function (string $name, string $method, string $uri, array $data = [], int|array $expected = 200, bool $csrf = true, ?array $multipart = null) use (&$client, &$token, &$report, $base) {
    if (str_starts_with($uri, 'http') && ! str_starts_with($uri, $base.'/')) {
        throw new RuntimeException('Only the local application may be tested.');
    }
    $options = ['headers' => ['Accept' => 'application/json']];
    if ($method === 'GET') {
        $options['headers']['Accept'] = 'text/html';
    } elseif ($csrf && $token) {
        $options['headers']['X-CSRF-TOKEN'] = $token;
    }
    if ($multipart !== null) {
        $options['multipart'] = $multipart;
    } elseif ($method !== 'GET') {
        $options['json'] = $data;
    }
    $start = microtime(true);
    $response = $client->request($method, $uri, $options);
    $body = (string) $response->getBody();
    if ($method === 'GET' && preg_match('/<meta name="csrf-token" content="([^"]+)"/', $body, $matches)) {
        $token = html_entity_decode($matches[1]);
    } elseif ($method === 'GET' && preg_match('/name="_token" value="([^"]+)"/', $body, $matches)) {
        $token = html_entity_decode($matches[1]);
    }
    $valid = in_array($response->getStatusCode(), (array) $expected, true);
    $entry = ['name' => $name, 'method' => $method, 'path' => parse_url($uri, PHP_URL_PATH), 'expected' => (array) $expected, 'actual' => $response->getStatusCode(), 'passed' => $valid, 'seconds' => round(microtime(true) - $start, 3)];
    if (! $valid) {
        $entry['validation_fields'] = array_keys(json_decode($body, true)['errors'] ?? []);
    }
    $report['steps'][] = $entry;
    echo ($valid ? 'PASS ' : 'FAIL ').$name.' ['.$response->getStatusCode()."]\n";
    if (! $valid) {
        throw new RuntimeException($name.' returned '.$response->getStatusCode().'; validation fields: '.implode(',', $entry['validation_fields'] ?? []));
    }

    return $response;
};
$id = function ($response): string {
    return basename(parse_url($response->getHeaderLine('Location'), PHP_URL_PATH));
};
$key = fn () => (string) Str::uuid();
$assert = function (string $name, bool $condition) use (&$report) {
    $report['steps'][] = ['name' => $name, 'method' => 'DATABASE ASSERTION', 'passed' => $condition];
    echo ($condition ? 'PASS ' : 'FAIL ').$name."\n";
    if (! $condition) {
        throw new RuntimeException($name);
    }
};
try {
    $http('Guest cannot open pharmacies', 'GET', '/pharmacies', [], 302);
    $http('Login page is reachable', 'GET', '/login');
    $http('Missing CSRF is rejected', 'POST', '/login', ['email' => 'qa@example.test', 'password' => 'WrongPassword123'], 419, false);
    $http('Wrong login rejected', 'POST', '/login', ['email' => 'qa-does-not-exist@example.test', 'password' => 'WrongPassword123'], 422);
    $http('Registration page', 'GET', '/register');
    $http('Weak password rejected', 'POST', '/register', ['name' => 'QA E2E', 'email' => 'qa-'.$run.'@example.test', 'password' => 'short', 'password_confirmation' => 'short'], 422);
    $password = 'LocalE2e'.bin2hex(random_bytes(14)).'2026';
    $email = 'qa-'.$run.'@example.test';
    $http('Create isolated QA account', 'POST', '/register', ['name' => 'QA E2E '.$run, 'email' => $email, 'password' => $password, 'password_confirmation' => $password, 'is_system_admin' => true], 302);
    $http('Authenticated session after registration', 'GET', '/pharmacies');
    $owner = User::where('email', $email)->firstOrFail();
    $assert('Public registration cannot grant system administrator', ! $owner->is_system_admin);
    $region = [];
    $parent = null;
    foreach (['province', 'city', 'district', 'village'] as $level) {
        $uri = '/master/regions'.($parent ? '?parent='.urlencode($parent) : '');
        $response = $http('Load '.$level.' options', 'GET', $uri);
        $row = json_decode((string) $response->getBody(), true)['data'][0] ?? null;
        if (! $row) {
            throw new RuntimeException('Region dataset must be imported before live E2E.');
        }
        $region[$level.'_code'] = $row['code'];
        $parent = $row['code'];
    }
    $response = $http('Create draft QA pharmacy', 'POST', '/api/v1/pharmacies', $region + ['code' => 'QA-'.substr(str_replace('-', '', $run), -15), 'name' => '[QA E2E] '.$run, 'ownership_type' => 'Individual',
        'phone' => '08000000001', 'owner_phone' => '08000000001', 'owner_name' => 'Owner QA E2E', 'address' => 'Alamat simulasi pengujian lokal', 'status' => 'ACTIVE'], 201);
    $pharmacy = Pharmacy::findOrFail(json_decode((string) $response->getBody(), true)['data']['id']);
    $root = '/a/'.$pharmacy->id;
    $report['fixtures'] = ['pharmacy_id' => $pharmacy->id, 'pharmacy_code' => $pharmacy->code, 'owner_id' => $owner->id, 'name' => $pharmacy->name];
    $assert('New pharmacy cannot self activate', $pharmacy->status === 'DRAFT');
    $http('Draft pharmacy blocks purchase', 'POST', $root.'/purchases', [], 422);
    $http('Owner cannot activate pharmacy', 'POST', $root.'/master/activate', [], 403);
    $process = new Process([PHP_BINARY, 'artisan', 'apotek:system-admin', $email], base_path());
    $process->mustRun();
    $http('Incomplete pharmacy cannot activate', 'POST', $root.'/master/activate', [], 422);
    $http('Save pharmacy license', 'PUT', $root.'/master/license', ['nib' => '1234567890123', 'license_number' => 'QA-SIA-'.$run, 'has_expiry' => 1, 'issued_on' => '2026-01-01', 'expires_on' => '2030-12-31', 'npwp' => '1234567890123456', 'document_status' => 'Recorded'], 302);
    $http('Create responsible pharmacist', 'POST', $root.'/master/personnel/people', ['name' => 'Apoteker QA', 'str_number' => 'QA-STR', 'sip_number' => 'QA-SIP', 'sip_expires_on' => '2030-12-31', 'active' => true], 302);
    $person = $pharmacy->personnel()->firstOrFail();
    $http('Assign responsible pharmacist', 'POST', $root.'/master/personnel', ['personnel_id' => $person->id, 'starts_on' => '2026-01-01'], 302);
    $http('Upload local synthetic license document', 'POST', $root.'/master/documents', [], 302, true, [['name' => 'type', 'contents' => 'license'], ['name' => 'name', 'contents' => 'QA synthetic license'], ['name' => 'expires_on', 'contents' => '2030-12-31'], ['name' => 'file', 'filename' => 'qa.pdf', 'contents' => "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF", 'headers' => ['Content-Type' => 'application/pdf']]]);
    $http('Save complete opening hours', 'PUT', $root.'/master/hours', ['hours' => array_map(fn ($day) => ['day' => $day, 'is_open' => false, 'overnight' => false], range(0, 6))], 302);
    $http('Activate complete QA pharmacy', 'POST', $root.'/master/activate', [], 302);
    $http('Owner workspace page', 'GET', $root);
    foreach (['UMUM' => false, 'RESEP' => true] as $sku => $rx) {
        $payload = ['sku' => 'QA-'.$sku, 'brand' => 'QA '.$sku, 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => 500, 'purchase_price' => 100, 'minimum_stock' => 1, 'requires_prescription' => $rx ? 1 : 0];
        $http('Create '.$sku.' product', 'POST', $root.'/products', $payload, 302);
        $http('Duplicate '.$sku.' SKU rejected', 'POST', $root.'/products', $payload, 422);
    }
    $products = $pharmacy->products()->get()->keyBy('sku');
    $general = $products['QA-UMUM'];
    $rxProduct = $products['QA-RESEP'];
    $http('Create supplier', 'POST', $root.'/suppliers', ['name' => 'Supplier QA '.$run, 'payment_days' => 30], 302);
    $supplier = $pharmacy->suppliers()->firstOrFail();
    $today = now($pharmacy->settings->timezone)->toDateString();
    $response = $http('Create purchase order', 'POST', $root.'/purchases', ['supplier_id' => $supplier->id, 'ordered_on' => $today, 'payment_type' => 'credit', 'payment_days' => 30, 'items' => $products->map(fn ($p) => ['product_id' => $p->id, 'unit_type' => 'base', 'quantity' => 20, 'unit_price' => 100, 'discount' => 0, 'tax' => 0])->values()->all()], 302);
    $order = $id($response);
    $http('Finalize purchase order', 'POST', $root.'/purchases/'.$order.'/finalize', [], 302);
    $items = DB::table('purchase_order_items')->where('purchase_order_id', $order)->get();
    $receiptInput = ['received_on' => $today, 'invoice_number' => 'QA-INV-'.$run, 'ppn_type' => 'percentage', 'ppn_value' => 11, 'items' => $items->map(fn ($i) => ['order_item_id' => $i->id, 'quantity' => 20, 'unit_price' => 100, 'batch_number' => 'QA-LOT', 'expires_on' => '2030-01-01', 'location' => 'Rak QA'])->all()];
    $invalid = $receiptInput;
    $invalid['items'][0]['quantity'] = 21;
    $http('Over receipt rejected', 'POST', $root.'/purchases/'.$order.'/receive', $invalid, 422);
    $receipt = $id($http('Create receipt', 'POST', $root.'/purchases/'.$order.'/receive', $receiptInput, 302));
    $http('Post receipt', 'POST', $root.'/receipts/'.$receipt.'/post', [], 302);
    $http('Duplicate receipt posting rejected', 'POST', $root.'/receipts/'.$receipt.'/post', [], 422);
    $assert('Receipt posts exactly 40 base units', (float) DB::table('stock_movements')->where('pharmacy_id', $pharmacy->id)->sum('quantity') === 40.0);
    $shift = $id($http('Open QA cashier shift', 'POST', $root.'/shifts', ['request_key' => $key(), 'opening_cash' => 10000], 302));
    $http('Duplicate open shift rejected', 'POST', $root.'/shifts', ['request_key' => $key(), 'opening_cash' => 10000], 422);
    $http('Prescription product rejected in general POS', 'POST', $root.'/sales', ['request_key' => $key(), 'items' => [['product_id' => $rxProduct->id, 'quantity' => 1, 'unit_type' => 'base']]], 422);
    $sale = $id($http('Create general POS sale', 'POST', $root.'/sales', ['request_key' => $key(), 'items' => [['product_id' => $general->id, 'quantity' => 4, 'unit_type' => 'base']]], 302));
    $http('Insufficient cash rejected', 'POST', $root.'/sales/'.$sale.'/pay', ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 100], 422);
    $http('Pay general POS sale', 'POST', $root.'/sales/'.$sale.'/pay', ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 2500], 302);
    $http('Repeated payment is idempotent', 'POST', $root.'/sales/'.$sale.'/pay', ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 2500], 302);
    $http('Open printable sales receipt', 'GET', $root.'/sales/'.$sale.'/receipt');
    $assert('One payment cash movement only', DB::table('cash_entries')->where('pharmacy_id', $pharmacy->id)->where('source_type', 'SALE')->where('source_id', $sale)->count() === 1);
    $http('Create patient', 'POST', $root.'/patients', ['request_key' => $key(), 'name' => 'Pasien QA '.$run, 'birth_date' => '1990-01-01', 'sex' => 'female'], 302);
    $patient = DB::table('patients')->where('pharmacy_id', $pharmacy->id)->first();
    $http('Create doctor', 'POST', $root.'/doctors', ['request_key' => $key(), 'name' => 'Dokter QA '.$run, 'sip_number' => 'QA-SIP'], 302);
    $doctor = DB::table('doctors')->where('pharmacy_id', $pharmacy->id)->first();
    $visit = $id($http('Register patient visit', 'POST', $root.'/visits', ['request_key' => $key(), 'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'type' => 'new', 'complaint' => 'Simulasi keluhan QA'], 302));
    $http('Start examination encounter', 'POST', $root.'/visits/'.$visit.'/transition', ['revision' => 1, 'action' => 'start'], 302);
    $http('Draft prescription before SOAP', 'PUT', $root.'/visits/'.$visit.'/prescription', ['revision' => 0, 'items' => [['product_id' => $rxProduct->id, 'quantity' => 3, 'dose' => '1 tablet', 'frequency' => '2 kali', 'instructions' => 'Simulasi QA']]], 302);
    $http('Finalize prescription before SOAP', 'POST', $root.'/visits/'.$visit.'/prescription/finalize', ['revision' => 1], 302);
    $rx = DB::table('prescriptions')->where('visit_id', $visit)->first();
    $dispense = ['request_key' => $key(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $rxProduct->id, 'quantity' => 1]]];
    $dispenseUri = $root.'/dispensing/'.$rx->id;
    $bad = $dispense;
    $bad['items'][0]['quantity'] = 4;
    $http('Prescription cannot be over redeemed', 'POST', $dispenseUri, $bad, 422);
    $redemption = $id($http('Prepare partial prescription redemption', 'POST', $dispenseUri, $dispense, 302));
    $http('Create service tariff', 'POST', $root.'/operations/master/tariffs', ['name' => 'Konsultasi QA', 'price' => 1000, 'active' => 1], 302);
    $tariff = DB::table('service_tariffs')->where('pharmacy_id', $pharmacy->id)->first();
    $bill = $id($http('Combine service and prescription bill', 'POST', $root.'/billing', ['request_key' => $key(), 'visit_id' => $visit, 'sale_id' => $redemption, 'items' => [['tariff_id' => $tariff->id, 'quantity' => 1]]], 302));
    $http('Linked medicine cannot bypass combined bill', 'POST', $root.'/sales/'.$redemption.'/pay', ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 500], 422);
    $http('Pay combined patient bill', 'POST', $root.'/billing/'.$bill.'/pay', ['revision' => 1, 'confirmed' => 1, 'payment_method' => 'cash', 'amount_received' => 1500], 302);
    $http('Hand over prescription medicine', 'POST', $root.'/dispensing/transactions/'.$redemption.'/handover', [], 302);
    $http('Open dispensing labels', 'GET', $root.'/dispensing/transactions/'.$redemption.'/labels');
    $http('Save SOAP documentation', 'PUT', $root.'/visits/'.$visit.'/examination', ['revision' => 0, 'documentation_mode' => 'FORM', 'subjective' => 'Keluhan simulasi', 'objective' => 'Temuan simulasi', 'assessment' => 'Assessment simulasi', 'plan' => 'Rencana simulasi'], 302);
    $http('Finalize SOAP', 'POST', $root.'/visits/'.$visit.'/examination/finalize', ['revision' => 1], 302);
    $http('Create expense category', 'POST', $root.'/operations/master/categories', ['name' => 'Biaya QA', 'active' => 1], 302);
    $category = DB::table('expense_categories')->where('pharmacy_id', $pharmacy->id)->first();
    $expense = $id($http('Create operating expense', 'POST', $root.'/expenses', ['request_key' => $key(), 'category_id' => $category->id, 'spent_on' => $today, 'amount' => 200, 'method' => 'cash', 'description' => 'Simulasi biaya QA'], 302));
    $http('Expense cannot post before approval', 'POST', $root.'/expenses/'.$expense.'/post', ['revision' => 1, 'confirmed' => 1], 422);
    $http('Approve operating expense', 'POST', $root.'/expenses/'.$expense.'/approve', ['revision' => 1], 302);
    $http('Post operating expense', 'POST', $root.'/expenses/'.$expense.'/post', ['revision' => 2, 'confirmed' => 1], 302);
    $soldMovement = DB::table('stock_movements')->where('pharmacy_id', $pharmacy->id)->where('sale_item_id', DB::table('sale_items')->where('sale_id', $sale)->value('id'))->where('type', 'SALE')->first();
    $saleReturn = $id($http('Create general sales return', 'POST', $root.'/returns/from/'.$sale, ['request_key' => $key(), 'reason' => 'Retur simulasi QA', 'condition_checked' => 1, 'items' => [['sale_movement_id' => $soldMovement->id, 'quantity' => 1, 'disposition' => 'RESTOCK']]], 302));
    $http('Approve customer return', 'POST', $root.'/returns/'.$saleReturn.'/approve', ['revision' => 1], 302);
    $http('Refund customer', 'POST', $root.'/returns/'.$saleReturn.'/refund', ['revision' => 2, 'refund_method' => 'cash', 'refund_confirmed' => 1], 302);
    $http('Duplicate refund remains idempotent', 'POST', $root.'/returns/'.$saleReturn.'/refund', ['revision' => 2, 'refund_method' => 'cash', 'refund_confirmed' => 1], 302);
    $http('Printed customer refund receipt', 'GET', $root.'/returns/'.$saleReturn.'/receipt');
    $invoice = DB::table('supplier_invoices')->where('pharmacy_id', $pharmacy->id)->where('purchase_receipt_id', $receipt)->first();
    $paymentInput = ['request_key' => $key(), 'amount' => 1000, 'paid_on' => $today, 'method' => 'transfer', 'reference' => 'QA-SUPPLIER'];
    $badPayment = $paymentInput;
    $badPayment['amount'] = 4441;
    $http('Supplier overpayment rejected', 'POST', $root.'/payables/invoices/'.$invoice->id.'/payments', $badPayment, 422);
    $payment = $id($http('Create supplier partial payment', 'POST', $root.'/payables/invoices/'.$invoice->id.'/payments', $paymentInput, 302));
    $http('Supplier payment needs approval', 'POST', $root.'/payables/payments/'.$payment.'/post', ['revision' => 1, 'paid_confirmed' => 1], 422);
    $http('Approve supplier payment', 'POST', $root.'/payables/payments/'.$payment.'/approve', ['revision' => 1], 302);
    $http('Post supplier partial payment', 'POST', $root.'/payables/payments/'.$payment.'/post', ['revision' => 2, 'paid_confirmed' => 1], 302);
    $receiptLine = DB::table('purchase_receipt_items as r')->join('purchase_order_items as i', 'i.id', '=', 'r.purchase_order_item_id')->where('r.purchase_receipt_id', $receipt)->where('i.product_id', $general->id)->select('r.id')->first();
    $makeSupplierReturn = function (int $quantity) use ($http, $id, $key, $root, $receipt, $receiptLine) {
        $return = $id($http('Create supplier return '.$quantity.' units', 'POST', $root.'/supplier-returns', ['request_key' => $key(), 'receipt_id' => $receipt, 'reason' => 'Simulasi retur supplier QA', 'items' => [['id' => $receiptLine->id, 'quantity' => $quantity]]], 302));
        $http('Post supplier return '.$quantity.' units', 'POST', $root.'/supplier-returns/'.$return.'/post', ['revision' => 1, 'accepted' => 1], 302);

        return $return;
    };
    $supplierReturn = $makeSupplierReturn(2);
    $assert('Supplier return reduces debt by actual price plus VAT', (float) DB::table('supplier_returns')->find($supplierReturn)->credit_amount === 222.0);
    $payment = $id($http('Create final supplier payment', 'POST', $root.'/payables/invoices/'.$invoice->id.'/payments', ['request_key' => $key(), 'amount' => 3218, 'paid_on' => $today, 'method' => 'transfer', 'reference' => 'QA-FINAL'], 302));
    $http('Approve final supplier payment', 'POST', $root.'/payables/payments/'.$payment.'/approve', ['revision' => 1], 302);
    $http('Post final supplier payment', 'POST', $root.'/payables/payments/'.$payment.'/post', ['revision' => 2, 'paid_confirmed' => 1], 302);
    $supplierReturn = $makeSupplierReturn(1);
    $assert('Paid purchase return creates supplier receivable', (float) DB::table('supplier_returns')->find($supplierReturn)->refund_due === 111.0);
    $http('Record supplier refund received', 'POST', $root.'/supplier-returns/'.$supplierReturn.'/recover', ['revision' => 2, 'confirmed' => 1, 'method' => 'transfer', 'reference' => 'QA-RECOVERY'], 302);
    $stocktake = $id($http('Create stocktake snapshot', 'POST', $root.'/stocktakes', ['request_key' => $key(), 'product_id' => $general->id], 302));
    $line = DB::table('stocktake_lines')->where('stocktake_id', $stocktake)->first();
    $http('Incomplete stocktake cannot approve', 'POST', $root.'/stocktakes/'.$stocktake.'/approve', ['revision' => 1], 422);
    $http('Count stocktake with explained discrepancy', 'PUT', $root.'/stocktakes/'.$stocktake.'/counts', ['revision' => 1, 'lines' => [['id' => $line->id, 'physical_quantity' => 13, 'reason' => 'Selisih fisik QA']]], 302);
    $http('Approve stocktake', 'POST', $root.'/stocktakes/'.$stocktake.'/approve', ['revision' => 2], 302);
    $http('Post stocktake adjustment', 'POST', $root.'/stocktakes/'.$stocktake.'/post', ['revision' => 3], 302);
    $assert('Final general stock reconciles to physical count', (float) DB::table('stock_movements')->where('pharmacy_id', $pharmacy->id)->where('product_id', $general->id)->sum('quantity') === 13.0);
    foreach (['payables', 'supplier-returns', 'inventory', 'stocktakes', 'sales', 'returns', 'patients', 'doctors', 'visits', 'examinations', 'billing', 'expenses', 'shifts', 'audit', 'users', 'roles', 'master'] as $page) {
        $http('Read module '.$page, 'GET', $root.'/'.$page);
    }
    foreach (['sales', 'purchases', 'inventory', 'finance'] as $kind) {
        $http('Read '.$kind.' report', 'GET', $root.'/reports?kind='.$kind);
        $response = $http('Export '.$kind.' Excel', 'GET', $root.'/reports?kind='.$kind.'&export=xlsx');
        $assert('Excel '.$kind.' has attachment header', str_contains($response->getHeaderLine('Content-Disposition'), 'attachment'));
    }
    $http('Invalid report period rejected', 'GET', $root.'/reports?from=2026-12-31&to=2026-01-01', [], 302);
    $expected = CashManagement::expected(DB::table('cash_shifts')->find($shift));
    $assert('Cash equals opening + net receipts - expenses', $expected === 1280000);
    $http('Close shift with exact physical cash', 'POST', $root.'/shifts/'.$shift.'/close', ['revision' => 1, 'counted_cash' => CashManagement::amount($expected)], 302);
    $http('Repeated closing is idempotent', 'POST', $root.'/shifts/'.$shift.'/close', ['revision' => 1, 'counted_cash' => CashManagement::amount($expected)], 302);
    $assert('Cash reconciliation has zero difference', (float) DB::table('cash_shifts')->find($shift)->difference === 0.0);
    $http('Create read-only staff role', 'POST', $root.'/roles', ['name' => 'QA Pembaca', 'permissions' => ['sales.view', 'patients.view', 'billing.view', 'expenses.view', 'supplier_returns.view', 'shifts.view', 'dashboard.view', 'reports.sales']], 302);
    $qaRole = $pharmacy->roles()->where('name', 'QA Pembaca')->firstOrFail();
    $foreignResponse = $http('Create second isolated QA tenant', 'POST', '/api/v1/pharmacies', $region + ['code' => 'QB-'.substr(str_replace('-', '', $run), -15), 'name' => '[QA BOUNDARY] '.$run, 'ownership_type' => 'Individual', 'phone' => '08000000003', 'owner_phone' => '08000000003', 'owner_name' => 'QA B', 'address' => 'Alamat simulasi QA B'], 201);
    $foreignId = json_decode((string) $foreignResponse->getBody(), true)['data']['id'];
    $report['fixtures']['boundary_pharmacy_id'] = $foreignId;
    $http('Foreign tenant cannot read sale by UUID', 'GET', '/a/'.$foreignId.'/sales/'.$sale, [], 404);
    $http('Foreign tenant cannot edit product by UUID', 'GET', '/a/'.$foreignId.'/products/'.$general->id.'/edit', [], 404);
    $ownerClient = $client;
    $ownerToken = $token;
    $client = new Client(['base_uri' => $base, 'cookies' => new CookieJar, 'allow_redirects' => false, 'http_errors' => false, 'timeout' => 35]);
    $token = null;
    $http('Staff registration page', 'GET', '/register');
    $staffEmail = 'staff-'.$run.'@example.test';
    $staffPassword = 'StaffE2e'.bin2hex(random_bytes(14)).'2026';
    $http('Create isolated QA staff account', 'POST', '/register', ['name' => 'QA Readonly '.$run, 'email' => $staffEmail, 'password' => $staffPassword, 'password_confirmation' => $staffPassword], 302);
    $staff = User::where('email', $staffEmail)->firstOrFail();
    $membership = $pharmacy->memberships()->create(['user_id' => $staff->id, 'active' => true]);
    app(AccessManager::class)->syncRoles($membership, collect([$qaRole]));
    $report['fixtures']['staff_id'] = $staff->id;
    $http('Staff authenticated session', 'GET', '/pharmacies');
    $http('Read-only staff can view sales', 'GET', $root.'/sales');
    $http('Read-only staff cannot create sale', 'POST', $root.'/sales', ['request_key' => $key(), 'items' => [['product_id' => $general->id, 'quantity' => 1, 'unit_type' => 'base']]], 403);
    $http('Read-only staff cannot pay sale', 'POST', $root.'/sales/'.$sale.'/pay', ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 2000], 403);
    $http('Read-only staff cannot write SOAP', 'PUT', $root.'/visits/'.$visit.'/examination', ['revision' => 2], 403);
    $http('Read-only staff cannot post expense', 'POST', $root.'/expenses/'.$expense.'/post', ['revision' => 3, 'confirmed' => 1], 403);
    $http('Read-only staff cannot view other cashier shift', 'GET', $root.'/shifts/'.$shift, [], 403);
    $http('Read-only staff cannot export financial report', 'GET', $root.'/reports?kind=sales&export=xlsx', [], 403);
    $http('Staff logout', 'POST', '/logout', [], 302);
    $client = $ownerClient;
    $token = $ownerToken;
    $http('Logout terminates session', 'POST', '/logout', [], 302);
    $token = null;
    $http('Logged out session cannot read transactions', 'GET', $root.'/sales', [], 302);
    $report['result'] = 'PASSED';
    $status = 0;
} catch (Throwable $exception) {
    $report['result'] = 'FAILED';
    $report['error'] = $exception->getMessage();
    echo 'ERROR '.$exception->getMessage()."\n";
} finally {
    if ($owner) {
        $process = new Process([PHP_BINARY, 'artisan', 'apotek:system-admin', $owner->email, '--revoke'], base_path());
        $process->run();
        $report['admin_privilege_revoked'] = $process->isSuccessful();
    }
    $report['finished_at'] = now('Asia/Jakarta')->toIso8601String();
    $report['passed'] = count(array_filter($report['steps'], fn ($step) => $step['passed']));
    $report['failed'] = count($report['steps']) - $report['passed'];
    file_put_contents($dir.'/live-http-'.$run.'.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo json_encode(['result' => $report['result'], 'passed' => $report['passed'], 'failed' => $report['failed'], 'report' => 'storage/app/testing-reports/2026-11-11/live-http-'.$run.'.json'])."\n";
}
exit($status);
