<?php

use App\Http\Controllers\AccessController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DispensingController;
use App\Http\Controllers\DoctorController;
use App\Http\Controllers\ExaminationController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\MasterPharmacyController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\OwnerOtpController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PrescriptionController;
use App\Http\Controllers\PricingRuleController;
use App\Http\Controllers\ProcurementController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleReturnController;
use App\Http\Controllers\StocktakeController;
use App\Http\Controllers\SupplierPayableController;
use App\Http\Controllers\VisitController;
use App\Http\Middleware\PrivateClinicalResponse;
use App\Models\Pharmacy;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'pharmacies.index' : 'login'));
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::get('/owner-login/otp', [OwnerOtpController::class, 'show'])->name('owner-otp.show');
    Route::post('/owner-login/otp', [OwnerOtpController::class, 'verify'])->middleware('throttle:10,1')->name('owner-otp.verify');
    Route::post('/owner-login/otp/resend', [OwnerOtpController::class, 'resend'])->middleware('throttle:3,15')->name('owner-otp.resend');
    Route::post('/owner-login/otp/cancel', [OwnerOtpController::class, 'cancel'])->name('owner-otp.cancel');
    Route::view('/register', 'auth.register')->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::view('/forgot-password', 'auth.forgot')->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/reset-password/{token}', fn (string $token) => view('auth.reset', compact('token')))->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
});
Route::get('/invitations/{token}', [AccessController::class, 'invitationShow'])->middleware('throttle:30,1')->name('invitations.show');
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/profile', [AuthController::class, 'profile'])->name('profile.edit');
    Route::put('/profile', [AuthController::class, 'updateProfile'])->name('profile.update');
    Route::put('/profile/password', [AuthController::class, 'password'])->middleware('throttle:5,1')->name('profile.password');
    Route::get('/pharmacies', [AccessController::class, 'pharmacies'])->name('pharmacies.index');
    Route::delete('/pharmacies/{pharmacy}', [AccessController::class, 'deletePharmacy'])->name('pharmacies.destroy');
    Route::get('/pharmacies/create', [MasterPharmacyController::class, 'create'])->name('master.create');
    Route::post('/pharmacies', [MasterPharmacyController::class, 'store'])->middleware('throttle:5,1')->name('pharmacies.store');
    Route::get('/master/regions', [MasterPharmacyController::class, 'regions'])->name('master.regions');
    Route::get('/system/pharmacies', [MasterPharmacyController::class, 'index'])->middleware('system.admin')->name('system.pharmacies');
    Route::post('/invitations/{token}', [AccessController::class, 'invitationAccept'])->middleware('throttle:10,1')->name('invitations.accept');
    Route::prefix('/a/{pharmacy}')->middleware('pharmacy')->group(function () {
        Route::get('/branding/logo', [MasterPharmacyController::class, 'brandLogo'])->name('master.brand-logo');
        $procurement = ProcurementController::class;
        Route::middleware(PrivateClinicalResponse::class)->group(function () {
            Route::get('/cashier', [\App\Http\Controllers\CashierController::class, 'index'])->name('cashier.index');
            Route::get('/examinations/soap-tasks', [\App\Http\Controllers\SoapReminderController::class, 'index'])->middleware('permission:examinations.view')->name('soap-tasks.index');
            $operations = OperationsController::class;
            Route::prefix('supplier-returns')->name('supplier-returns.')->middleware('permission:supplier_returns.view')->group(function () use ($operations) {
                Route::get('/', [$operations, 'returnsIndex'])->name('index');
                Route::get('/create', [$operations, 'returnCreate'])->middleware('permission:supplier_returns.create')->name('create');
                Route::post('/', [$operations, 'returnStore'])->middleware('pharmacy.active')->name('store');
                Route::get('/{record}', [$operations, 'returnShow'])->whereUuid('record')->name('show');
                Route::post('/{record}/{action}', [$operations, 'returnAction'])->whereUuid('record')->whereIn('action', ['post', 'cancel', 'recover'])->middleware('pharmacy.active')->name('action');
            });
            Route::prefix('billing')->name('billing.')->middleware('permission:billing.view')->group(function () use ($operations) {
                Route::get('/', [$operations, 'billingIndex'])->name('index');
                Route::get('/create', [$operations, 'billingCreate'])->middleware('permission:billing.create')->name('create');
                Route::post('/', [$operations, 'billingStore'])->middleware('pharmacy.active')->name('store');
                Route::get('/{record}', [$operations, 'billingShow'])->whereUuid('record')->name('show');
                Route::post('/{record}/{action}', [$operations, 'billingAction'])->whereUuid('record')->whereIn('action', ['pay', 'cancel'])->middleware('pharmacy.active')->name('action');
            });
            Route::get('/operations/master/{kind}', [$operations, 'catalogs'])->whereIn('kind', ['tariffs', 'categories'])->name('operations.catalog');
            Route::post('/operations/master/{kind}', [$operations, 'catalogSave'])->whereIn('kind', ['tariffs', 'categories'])->middleware('pharmacy.active')->name('operations.catalog.save');
            Route::prefix('expenses')->name('expenses.')->middleware('permission:expenses.view')->group(function () use ($operations) {
                Route::get('/', [$operations, 'expensesIndex'])->name('index');
                Route::get('/create', [$operations, 'expenseCreate'])->middleware('permission:expenses.create')->name('create');
                Route::post('/', [$operations, 'expenseStore'])->middleware('pharmacy.active')->name('store');
                Route::get('/{record}', [$operations, 'expenseShow'])->whereUuid('record')->name('show');
                Route::post('/{record}/{action}', [$operations, 'expenseAction'])->whereUuid('record')->whereIn('action', ['approve', 'post', 'cancel'])->middleware('pharmacy.active')->name('action');
            });
            Route::prefix('shifts')->name('shifts.')->middleware('permission:shifts.view')->group(function () use ($operations) {
                Route::get('/', [$operations, 'shiftsIndex'])->name('index');
                Route::post('/', [$operations, 'shiftOpen'])->middleware('pharmacy.active')->name('open');
                Route::get('/{record}', [$operations, 'shiftShow'])->whereUuid('record')->name('show');
                Route::post('/{record}/{action}', [$operations, 'shiftAction'])->whereUuid('record')->whereIn('action', ['close', 'deposit'])->middleware('pharmacy.active')->name('action');
            });
            Route::get('/reports', [$operations, 'reports'])->name('reports.index');
            Route::get('/reports/profit-loss', [$operations, 'profitLoss'])->name('profit-loss.index');
            Route::get('/reports/psychotropic', [\App\Http\Controllers\PsychotropicReportController::class, 'index'])->middleware('permission:reports.inventory')->name('psychotropic.index');
            Route::post('/reports/psychotropic/movements/{movement}', [\App\Http\Controllers\PsychotropicReportController::class, 'classify'])->whereUuid('movement')->middleware('permission:reports.inventory')->name('psychotropic.classify');
        });
        Route::middleware(PrivateClinicalResponse::class)->group(function () {
            Route::middleware('permission:dispensing.view')->prefix('combined-dispensing')->name('combined-dispensing.')->group(function () {
                Route::get('/', [\App\Http\Controllers\CombinedDispensingController::class, 'index'])->name('index');
                Route::post('/', [\App\Http\Controllers\CombinedDispensingController::class, 'store'])->middleware('pharmacy.active')->name('store');
                Route::post('/pay', [\App\Http\Controllers\CombinedDispensingController::class, 'pay'])->middleware(['permission:sales.pay', 'pharmacy.active'])->name('pay');
            });
            Route::middleware('permission:dispensing.view')->prefix('dispensing')->name('dispensing.')->group(function () {
                $controller = DispensingController::class;
                Route::get('/', [$controller, 'index'])->name('index');
                Route::get('/{prescription}', [$controller, 'show'])->whereUuid('prescription')->name('show');
                Route::post('/{prescription}', [$controller, 'store'])->whereUuid('prescription')->middleware('pharmacy.active')->name('store');
                Route::post('/transactions/{sale}/handover', [$controller, 'handover'])->whereUuid('sale')->middleware(['permission:dispensing.handover', 'pharmacy.active'])->name('handover');
                Route::get('/transactions/{sale}/labels', [$controller, 'labels'])->whereUuid('sale')->middleware('permission:dispensing.print')->name('labels');
            });
            Route::middleware('permission:prescriptions.view')->prefix('visits/{visit}/prescription')->whereUuid('visit')->name('prescriptions.')->group(function () {
                $controller = PrescriptionController::class;
                Route::get('/', [$controller, 'show'])->name('show');
                Route::get('/edit', [$controller, 'edit'])->middleware('pharmacy.active')->name('edit');
                Route::put('/', [$controller, 'save'])->middleware('pharmacy.active')->name('save');
                Route::post('/finalize', [$controller, 'finalize'])->middleware(['permission:prescriptions.finalize', 'pharmacy.active'])->name('finalize');
                Route::get('/print', [$controller, 'print'])->middleware('permission:prescriptions.print')->name('print');
                Route::get('/signature', [$controller, 'signature'])->name('signature');
            });
            Route::middleware('permission:examinations.view')->name('examinations.')->group(function () {
                $controller = ExaminationController::class;
                Route::get('/examinations', [$controller, 'index'])->name('index');
                Route::prefix('visits/{visit}/examination')->whereUuid('visit')->group(function () use ($controller) {
                    Route::get('/', [$controller, 'show'])->name('show');
                    Route::put('/encounter', [$controller, 'saveEncounter'])->middleware('pharmacy.active')->name('encounter.save');
                    Route::get('/edit', [$controller, 'edit'])->middleware(['permission:examinations.update', 'pharmacy.active'])->name('edit');
                    Route::put('/', [$controller, 'save'])->middleware(['permission:examinations.update', 'pharmacy.active'])->name('save');
                    Route::post('/finalize', [$controller, 'finalize'])->middleware(['permission:examinations.finalize', 'pharmacy.active'])->name('finalize');
                    Route::get('/amend', [$controller, 'edit'])->middleware(['permission:examinations.amend', 'pharmacy.active'])->name('amend_form');
                    Route::post('/amend', [$controller, 'amend'])->middleware(['permission:examinations.amend', 'pharmacy.active'])->name('amend');
                    Route::get('/versions/{version}', [$controller, 'version'])->whereUuid('version')->name('version');
                    Route::get('/images/{image}', [$controller, 'image'])->whereUuid('image')->name('image');
                    Route::post('/consultation', [$controller, 'saveConsultation'])->middleware('pharmacy.active')->name('consultation.save');
                    Route::get('/consultation/versions/{evidence}', [$controller, 'consultationVersion'])->whereUuid('evidence')->name('consultation.version');
                    Route::get('/consultation/images/{image}', [$controller, 'consultationImage'])->whereUuid('image')->name('consultation.image');
                });
            });
            Route::middleware('permission:visits.view')->prefix('visits')->name('visits.')->group(function () {
                $controller = VisitController::class;
                Route::get('/', [$controller, 'index'])->name('index');
                Route::get('/create', [$controller, 'create'])->middleware(['permission:visits.create', 'pharmacy.active'])->name('create');
                Route::post('/', [$controller, 'store'])->middleware(['permission:visits.create', 'pharmacy.active'])->name('store');
                Route::get('/patients', [$controller, 'patients'])->middleware(['permission:visits.create', 'pharmacy.active'])->name('patients');
                Route::get('/{visit}', [$controller, 'show'])->whereUuid('visit')->name('show');
                Route::get('/{visit}/edit', [$controller, 'edit'])->whereUuid('visit')->middleware(['permission:visits.update', 'pharmacy.active'])->name('edit');
                Route::put('/{visit}', [$controller, 'update'])->whereUuid('visit')->middleware(['permission:visits.update', 'pharmacy.active'])->name('update');
                // The service checks the specific start/cancel permission within the transaction.
                Route::post('/{visit}/transition', [$controller, 'transition'])->whereUuid('visit')->name('transition');
                Route::post('/{visit}/complete', [$controller, 'complete'])->whereUuid('visit')->middleware(['permission:visits.complete', 'pharmacy.active'])->name('complete');
            });
            Route::get('/consultation-follow-up', [PatientController::class, 'followUp'])->middleware('permission:dispensing.view')->name('patients.follow-up');
            Route::post('/consultation-follow-up/{prescription}', [PatientController::class, 'updateFollowUp'])->whereUuid('prescription')->middleware(['permission:dispensing.view', 'permission:dispensing.prepare', 'pharmacy.active'])->name('patients.follow-up.update');
            Route::middleware('permission:patients.view')->prefix('patients')->name('patients.')->group(function () {
                $controller = PatientController::class;
                Route::get('/', [$controller, 'index'])->name('index');
                Route::get('/create', [$controller, 'create'])->middleware('permission:patients.create')->name('create');
                Route::post('/', [$controller, 'store'])->middleware('permission:patients.create')->name('store');
                Route::get('/{patient}', [$controller, 'show'])->whereUuid('patient')->name('show');
                Route::get('/{patient}/agreement', [$controller, 'agreement'])->whereUuid('patient')->middleware('permission:patients.view_sensitive')->name('agreement');
                Route::get('/{patient}/edit', [$controller, 'edit'])->whereUuid('patient')->middleware('permission:patients.update')->name('edit');
                Route::put('/{patient}', [$controller, 'update'])->whereUuid('patient')->middleware('permission:patients.update')->name('update');
                Route::patch('/{patient}/status', [$controller, 'status'])->whereUuid('patient')->middleware('permission:patients.deactivate')->name('status');
            });
            Route::middleware('permission:doctors.view')->prefix('doctors')->name('doctors.')->group(function () {
                $controller = DoctorController::class;
                Route::get('/', [$controller, 'index'])->name('index');
                Route::get('/create', [$controller, 'create'])->middleware('permission:doctors.create')->name('create');
                Route::post('/', [$controller, 'store'])->middleware('permission:doctors.create')->name('store');
                Route::get('/{doctor}', [$controller, 'show'])->whereUuid('doctor')->name('show');
                Route::get('/{doctor}/edit', [$controller, 'edit'])->whereUuid('doctor')->middleware('permission:doctors.update')->name('edit');
                Route::put('/{doctor}', [$controller, 'update'])->whereUuid('doctor')->middleware('permission:doctors.update')->name('update');
                Route::patch('/{doctor}/status', [$controller, 'status'])->whereUuid('doctor')->middleware('permission:doctors.deactivate')->name('status');
                Route::post('/{doctor}/account', [$controller, 'account'])->whereUuid('doctor')->middleware('permission:doctors.link_account')->name('account');
                Route::post('/{doctor}/signatures', [$controller, 'signature'])->whereUuid('doctor')->middleware('permission:doctors.signature_update')->name('signatures');
                Route::get('/{doctor}/signatures/{signature}', [$controller, 'image'])->whereUuid(['doctor', 'signature'])->name('signature_image');
            });
        });
        Route::middleware(['permission:payables.view', PrivateClinicalResponse::class])->prefix('payables')->name('payables.')->group(function () {
            $controller = SupplierPayableController::class;
            Route::get('/', [$controller, 'index'])->name('index');
            Route::get('/invoices/{invoice}', [$controller, 'show'])->whereUuid('invoice')->name('show');
            Route::post('/invoices/{invoice}/reconcile', [$controller, 'reconcile'])->whereUuid('invoice')->middleware(['permission:payables.reconcile', 'pharmacy.active'])->name('reconcile');
            Route::post('/invoices/{invoice}/payments', [$controller, 'store'])->whereUuid('invoice')->middleware(['permission:payables.create', 'pharmacy.active'])->name('store');
            Route::get('/payments/{payment}', [$controller, 'payment'])->whereUuid('payment')->name('payment');
            Route::post('/payments/{payment}/approve', [$controller, 'approve'])->whereUuid('payment')->middleware(['permission:payables.approve', 'pharmacy.active'])->name('approve');
            Route::post('/payments/{payment}/post', [$controller, 'post'])->whereUuid('payment')->middleware(['permission:payables.post', 'pharmacy.active'])->name('post');
            Route::post('/payments/{payment}/cancel', [$controller, 'cancel'])->whereUuid('payment')->middleware(['permission:payables.cancel', 'pharmacy.active'])->name('cancel');
            Route::get('/payments/{payment}/proof', [$controller, 'proof'])->whereUuid('payment')->middleware('permission:payables.proof_view')->name('proof');
            Route::get('/payments/{payment}/receipt', [$controller, 'receipt'])->whereUuid('payment')->middleware('permission:payables.print')->name('receipt');
        });
        Route::middleware('permission:stocktakes.view')->prefix('stocktakes')->name('stocktakes.')->group(function () {
            $controller = StocktakeController::class;
            Route::get('/', [$controller, 'index'])->name('index');
            Route::get('/create', [$controller, 'create'])->middleware(['permission:stocktakes.create', 'pharmacy.active'])->name('create');
            Route::post('/', [$controller, 'store'])->middleware(['permission:stocktakes.create', 'pharmacy.active'])->name('store');
            Route::get('/{stocktake}', [$controller, 'show'])->whereUuid('stocktake')->name('show');
            Route::put('/{stocktake}/counts', [$controller, 'counts'])->whereUuid('stocktake')->middleware(['permission:stocktakes.update', 'pharmacy.active'])->name('counts');
            Route::post('/{stocktake}/lines', [$controller, 'addLine'])->whereUuid('stocktake')->middleware(['permission:stocktakes.update', 'pharmacy.active'])->name('lines');
            Route::post('/{stocktake}/approve', [$controller, 'approve'])->whereUuid('stocktake')->middleware(['permission:stocktakes.approve', 'pharmacy.active'])->name('approve');
            Route::post('/{stocktake}/post', [$controller, 'post'])->whereUuid('stocktake')->middleware(['permission:stocktakes.post', 'pharmacy.active'])->name('post');
            Route::post('/{stocktake}/cancel', [$controller, 'cancel'])->whereUuid('stocktake')->middleware('permission:stocktakes.cancel')->name('cancel');
        });
        Route::prefix('pricing/{kind}')->whereIn('kind', ['discount', 'tax'])->name('pricing.')->group(function () {
            $controller = PricingRuleController::class;
            Route::get('/', [$controller, 'index'])->name('index');
            Route::get('/create', [$controller, 'form'])->name('create');
            Route::post('/', [$controller, 'save'])->name('store');
            Route::get('/{rule}/edit', [$controller, 'form'])->whereUuid('rule')->name('edit');
            Route::put('/{rule}', [$controller, 'save'])->whereUuid('rule')->name('update');
            Route::patch('/{rule}/status', [$controller, 'status'])->whereUuid('rule')->name('status');
        });
        Route::middleware(['permission:returns.view', PrivateClinicalResponse::class])->prefix('returns')->name('returns.')->group(function () {
            $controller = SaleReturnController::class;
            Route::get('/', [$controller, 'index'])->name('index');
            Route::get('/select', [$controller, 'select'])->middleware(['permission:returns.create', 'pharmacy.active'])->name('select');
            Route::get('/from/{sale}', [$controller, 'create'])->whereUuid('sale')->middleware(['permission:returns.create', 'pharmacy.active'])->name('create');
            Route::post('/from/{sale}', [$controller, 'store'])->whereUuid('sale')->middleware(['permission:returns.create', 'pharmacy.active'])->name('store');
            Route::get('/{return}', [$controller, 'show'])->whereUuid('return')->name('show');
            Route::post('/{return}/approve', [$controller, 'approve'])->whereUuid('return')->middleware(['permission:returns.approve', 'pharmacy.active'])->name('approve');
            Route::post('/{return}/refund', [$controller, 'refund'])->whereUuid('return')->middleware(['permission:returns.refund', 'pharmacy.active'])->name('refund');
            Route::post('/{return}/cancel', [$controller, 'cancel'])->whereUuid('return')->middleware(['permission:returns.cancel', 'pharmacy.active'])->name('cancel');
            Route::post('/{return}/restore-entitlement', [$controller, 'restoreEntitlement'])->whereUuid('return')->middleware(['permission:returns.restore_entitlement', 'pharmacy.active'])->name('restore_entitlement');
            Route::get('/{return}/receipt', [$controller, 'receipt'])->whereUuid('return')->middleware('permission:returns.print')->name('receipt');
        });
        Route::middleware(['permission:sales.view', PrivateClinicalResponse::class])->prefix('sales')->name('sales.')->group(function () {
            $controller = SaleController::class;
            Route::get('/', [$controller, 'index'])->name('index');
            Route::get('/create', [$controller, 'create'])->middleware(['permission:sales.create', 'pharmacy.active'])->name('create');
            Route::get('/catalog', [$controller, 'catalog'])->middleware('permission:sales.create')->name('catalog');
            Route::post('/', [$controller, 'store'])->middleware(['permission:sales.create', 'pharmacy.active'])->name('store');
            Route::get('/{sale}', [$controller, 'show'])->whereUuid('sale')->name('show');
            Route::get('/{sale}/edit', [$controller, 'edit'])->whereUuid('sale')->middleware(['permission:sales.create', 'pharmacy.active'])->name('edit');
            Route::put('/{sale}', [$controller, 'update'])->whereUuid('sale')->middleware(['permission:sales.create', 'pharmacy.active'])->name('update');
            Route::post('/{sale}/pay', [$controller, 'pay'])->whereUuid('sale')->middleware(['permission:sales.pay', 'pharmacy.active'])->name('pay');
            Route::post('/{sale}/cancel', [$controller, 'cancel'])->whereUuid('sale')->middleware(['permission:sales.cancel', 'pharmacy.active'])->name('cancel');
            Route::get('/{sale}/receipt', [$controller, 'receipt'])->whereUuid('sale')->middleware('permission:sales.print')->name('receipt');
        });
        Route::middleware('permission:inventory.view')->prefix('inventory')->name('inventory.')->group(function () {
            Route::get('/', [InventoryController::class, 'index'])->name('index');
            Route::get('/{product}', [InventoryController::class, 'show'])->whereUuid('product')->name('show');
            Route::get('/{product}/ledger', [InventoryController::class, 'ledger'])->whereUuid('product')->name('ledger');
        });
        Route::get('/suppliers', [$procurement, 'suppliers'])->middleware('permission:suppliers.view')->name('suppliers.index');
        Route::get('/suppliers/create', [$procurement, 'supplierCreate'])->middleware('permission:suppliers.create')->name('suppliers.create');
        Route::get('/suppliers/{supplier}/edit', [$procurement, 'supplierEdit'])->whereUuid('supplier')->middleware('permission:suppliers.update')->name('suppliers.edit');
        Route::post('/suppliers', [$procurement, 'supplierSave'])->middleware('permission:suppliers.create')->name('suppliers.store');
        Route::put('/suppliers/{supplier}', [$procurement, 'supplierSave'])->whereUuid('supplier')->middleware('permission:suppliers.update')->name('suppliers.update');
        Route::patch('/suppliers/{supplier}/status', [$procurement, 'supplierStatus'])->whereUuid('supplier')->middleware('permission:suppliers.deactivate')->name('suppliers.status');
        Route::get('/purchases', [$procurement, 'orders'])->middleware('permission:purchases.view')->name('purchases.index');
        Route::get('/purchases/create', [$procurement, 'orderCreate'])->middleware(['permission:purchases.create', 'pharmacy.active'])->name('purchases.create');
        Route::post('/purchases', [$procurement, 'orderSave'])->middleware(['permission:purchases.create', 'pharmacy.active'])->name('purchases.store');
        Route::get('/purchases/{order}', [$procurement, 'orderShow'])->whereUuid('order')->middleware('permission:purchases.view')->name('purchases.show');
        Route::get('/purchases/{order}/letter', [$procurement, 'letterForm'])->whereUuid('order')->middleware('permission:purchases.view')->name('purchases.letter');
        Route::post('/purchases/{order}/letter', [$procurement, 'letterExport'])->whereUuid('order')->middleware('permission:purchases.view')->name('purchases.letter.export');
        Route::get('/purchases/{order}/edit', [$procurement, 'orderEdit'])->whereUuid('order')->middleware(['permission:purchases.update', 'pharmacy.active'])->name('purchases.edit');
        Route::put('/purchases/{order}', [$procurement, 'orderSave'])->whereUuid('order')->middleware(['permission:purchases.update', 'pharmacy.active'])->name('purchases.update');
        Route::post('/purchases/{order}/finalize', [$procurement, 'finalize'])->whereUuid('order')->middleware(['permission:purchases.finalize', 'pharmacy.active'])->name('purchases.finalize');
        Route::post('/purchases/{order}/cancel', [$procurement, 'cancel'])->whereUuid('order')->middleware(['permission:purchases.cancel', 'pharmacy.active'])->name('purchases.cancel');
        Route::get('/purchases/{order}/receive', [$procurement, 'receiptCreate'])->whereUuid('order')->middleware(['permission:receipts.create', 'pharmacy.active'])->name('receipts.create');
        Route::post('/purchases/{order}/receive', [$procurement, 'receiptSave'])->whereUuid('order')->middleware(['permission:receipts.create', 'pharmacy.active'])->name('receipts.store');
        Route::get('/receipts/{receipt}', [$procurement, 'receiptShow'])->whereUuid('receipt')->middleware('permission:receipts.view')->name('receipts.show');
        Route::get('/receipts/{receipt}/edit', [$procurement, 'receiptEdit'])->whereUuid('receipt')->middleware(['permission:receipts.create', 'pharmacy.active'])->name('receipts.edit');
        Route::put('/receipts/{receipt}', [$procurement, 'receiptUpdate'])->whereUuid('receipt')->middleware(['permission:receipts.create', 'pharmacy.active'])->name('receipts.update');
        Route::post('/receipts/{receipt}/post', [$procurement, 'post'])->whereUuid('receipt')->middleware(['permission:receipts.post', 'pharmacy.active'])->name('receipts.post');
        Route::get('/product-categories', [\App\Http\Controllers\ProductCategoryController::class, 'index'])->middleware('permission:drugs.view')->name('product-categories.index');
        Route::post('/product-categories', [\App\Http\Controllers\ProductCategoryController::class, 'save'])->middleware('permission:drugs.update')->name('product-categories.save');
        Route::get('/products', [ProductController::class, 'index'])->middleware('permission:drugs.view')->name('products.index');
        Route::get('/products/import', [ProductImportController::class, 'index'])->middleware('permission:drugs.create')->name('products.import');
        Route::get('/products/import/template', [ProductImportController::class, 'template'])->middleware('permission:drugs.create')->name('products.import.template');
        Route::post('/products/import', [ProductImportController::class, 'store'])->middleware('permission:drugs.create')->name('products.import.store');
        Route::get('/products/create', [ProductController::class, 'create'])->middleware('permission:drugs.create')->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->middleware('permission:drugs.create')->name('products.store');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->whereUuid('product')->middleware('permission:drugs.update')->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])->whereUuid('product')->middleware('permission:drugs.update')->name('products.update');
        Route::patch('/products/{product}/status', [ProductController::class, 'status'])->whereUuid('product')->middleware('permission:drugs.deactivate')->name('products.status');
        Route::get('/', [AccessController::class, 'home'])->name('pharmacy.home');
        Route::get('/profile', fn (Pharmacy $pharmacy) => redirect()->route('master.show', $pharmacy))->name('pharmacy.edit');
        Route::put('/profile', [MasterPharmacyController::class, 'update'])->name('pharmacy.update');
        Route::get('/users', [AccessController::class, 'users'])->middleware('permission:users.view')->name('users.index');
        Route::get('/users/invite', [AccessController::class, 'inviteForm'])->middleware('permission:users.invite')->name('users.invite');
        Route::post('/users/invite', [AccessController::class, 'invite'])->middleware(['permission:users.invite', 'throttle:10,1'])->name('users.send-invite');
        Route::get('/users/{member}/edit', [AccessController::class, 'memberEdit'])->middleware('permission:users.update')->name('users.edit');
        Route::put('/users/{member}/roles', [AccessController::class, 'memberUpdate'])->middleware('permission:users.update')->name('users.update');
        Route::put('/users/{member}/status', [AccessController::class, 'memberStatus'])->middleware('permission:users.deactivate')->name('users.status');
        Route::delete('/users/{member}', [AccessController::class, 'memberRemove'])->middleware('permission:users.remove')->name('users.remove');
        Route::delete('/invitations/{invitation}', [AccessController::class, 'revoke'])->middleware('permission:users.invite')->name('invitations.revoke');
        Route::get('/roles', [AccessController::class, 'roles'])->middleware('permission:roles.view')->name('roles.index');
        Route::get('/roles/create', [AccessController::class, 'roleForm'])->middleware('permission:roles.create')->name('roles.create');
        Route::post('/roles', [AccessController::class, 'roleSave'])->middleware('permission:roles.create')->name('roles.store');
        Route::get('/roles/{role}/edit', [AccessController::class, 'roleForm'])->middleware('permission:roles.update')->name('roles.edit');
        Route::put('/roles/{role}', [AccessController::class, 'roleSave'])->middleware('permission:roles.update')->name('roles.update');
        Route::delete('/roles/{role}', [AccessController::class, 'roleDelete'])->middleware('permission:roles.delete')->name('roles.delete');
        Route::get('/audit', [AccessController::class, 'audit'])->middleware('permission:audit.view')->name('audit.index');
    });
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    $controller = MasterPharmacyController::class;
    foreach (['web' => '/a/{pharmacy}/master', 'api' => '/api/v1/pharmacies/{pharmacy}'] as $kind => $prefix) {
        Route::prefix($prefix)->middleware('pharmacy')->name($kind === 'api' ? 'api.master.' : 'master.')->group(function () use ($controller) {
            Route::get('/', [$controller, 'show'])->name('show');
            Route::get('/edit', [$controller, 'edit'])->name('edit');
            Route::match(['patch', 'put'], '/', [$controller, 'update'])->name('update');
            Route::put('/license', [$controller, 'license'])->name('license');
            Route::patch('/contact', [$controller, 'contact'])->name('contact');
            Route::get('/settings', [$controller, 'settings'])->name('settings');
            Route::patch('/settings', [$controller, 'settingsSave'])->name('settings.save');
            Route::put('/hours', [$controller, 'hours'])->name('hours');
            Route::post('/personnel', [$controller, 'assign'])->name('assign');
            Route::post('/personnel/people', [$controller, 'personnel'])->name('personnel');
            Route::put('/personnel/people/{person}', [$controller, 'personnel'])->whereUuid('person')->name('personnel.update');
            Route::delete('/personnel/people/{person}', [$controller, 'deletePersonnel'])->whereUuid('person')->name('personnel.delete');
            Route::patch('/assignments/{assignment}/end', [$controller, 'endAssignment'])->whereUuid('assignment')->name('assignments.end');
            Route::post('/activate', [$controller, 'activate'])->middleware('system.admin')->name('activate');
            Route::post('/deactivate', [$controller, 'deactivate'])->middleware('system.admin')->name('deactivate');
            Route::post('/logo', [$controller, 'logo'])->name('logo.save');
            Route::get('/logo', [$controller, 'logoView'])->name('logo');
            Route::post('/documents', [$controller, 'upload'])->name('documents.upload');
            Route::get('/documents/{document}', [$controller, 'document'])->whereUuid('document')->name('documents.download');
            Route::delete('/documents/{document}', [$controller, 'deleteDocument'])->whereUuid('document')->name('documents.delete');
            Route::get('/audit-logs', [$controller, 'audit'])->name('audit');
        });
    }
    Route::post('/api/v1/pharmacies', [$controller, 'store'])->middleware('throttle:5,1')->name('api.master.store');
    Route::get('/api/v1/pharmacies', [$controller, 'index'])->name('api.master.index');
});
