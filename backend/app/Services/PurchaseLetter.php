<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Models\PurchaseOrder;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurchaseLetter
{
    public function context(Pharmacy $pharmacy, PurchaseOrder $order): array
    {
        abort_if($order->status === 'CANCELLED', 422, 'Pesanan yang dibatalkan tidak dapat diekspor.');
        $date = $order->ordered_on->toDateString();
        $assignment = $pharmacy->assignments()->where('role', 'APJ')->whereDate('starts_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))->latest('starts_on')->first();
        $person = $assignment?->credential_snapshot ?? [];
        $supplier = $pharmacy->suppliers()->find($order->supplier_id);
        $ingredients = DB::table('product_ingredients')->whereIn('product_id', $order->items->pluck('product_id'))->orderBy('position')->get()->groupBy('product_id');
        $defaults = ['kind' => 'obat', 'signer' => $person['name'] ?? '', 'sip' => $person['sip_number'] ?? '',
            'signer_address' => $pharmacy->address ?? '', 'signer_phone' => $pharmacy->phone ?? '',
            'supplier_address' => $supplier?->address ?? '',
            'city' => DB::table('regions')->where('code', $pharmacy->city_code)->value('name') ?? '',
            'ingredients' => [], 'strengths' => []];
        foreach ($order->items as $item) {
            $parts = $ingredients->get($item->product_id, collect());
            $defaults['ingredients'][$item->id] = $parts->pluck('name')->implode(', ');
            $defaults['strengths'][$item->id] = $parts->map(fn ($part) => (float) $part->amount.' '.$part->unit.((float) $part->basis_quantity !== 1.0 ? ' / '.(float) $part->basis_quantity.' '.$part->basis_unit : ''))->implode(', ');
        }

        return compact('pharmacy', 'order', 'defaults');
    }

    public function render(array $context, array $data): string
    {
        $logo = null;
        $path = $context['pharmacy']->logo_path;
        if ($path && Storage::disk('local')->exists($path)) {
            $mime = Storage::disk('local')->mimeType($path);
            if (in_array($mime, ['image/png', 'image/jpeg'])) {
                $logo = 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($path));
            }
        }
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Serif');
        $options->set('tempDir', storage_path('framework/cache'));
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'landscape');
        $pdf->loadHtml(view('procurement.letter-pdf', $context + compact('data', 'logo'))->render());
        $pdf->render();

        return $pdf->output();
    }

    public static function words(int $number): string
    {
        $names = ['nol', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
        if ($number < 12) {
            return $names[$number];
        }
        if ($number < 20) {
            return self::words($number - 10).' belas';
        }
        if ($number < 100) {
            return self::words(intdiv($number, 10)).' puluh'.($number % 10 ? ' '.self::words($number % 10) : '');
        }
        if ($number < 200) {
            return 'seratus'.($number % 100 ? ' '.self::words($number % 100) : '');
        }
        if ($number < 1000) {
            return self::words(intdiv($number, 100)).' ratus'.($number % 100 ? ' '.self::words($number % 100) : '');
        }
        if ($number < 2000) {
            return 'seribu'.($number % 1000 ? ' '.self::words($number % 1000) : '');
        }

        return self::words(intdiv($number, 1000)).' ribu'.($number % 1000 ? ' '.self::words($number % 1000) : '');
    }

    public static function quantity(string $quantity): string
    {
        [$whole, $fraction] = array_pad(explode('.', $quantity), 2, '');
        $fraction = rtrim($fraction, '0');
        $words = self::words((int) $whole).($fraction !== '' ? ' koma '.implode(' ', array_map(fn ($digit) => self::words((int) $digit), str_split($fraction))) : '');

        return $whole.($fraction !== '' ? ','.$fraction : '').' ('.$words.')';
    }
}
