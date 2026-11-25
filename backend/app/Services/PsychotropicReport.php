<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Shuchkin\SimpleXLSXGen;

class PsychotropicReport
{
    public const CATEGORIES = ['pbf' => 'Pemasukan PBF (retur mengurangi)', 'facility_in' => 'Pemasukan sarana (retur mengurangi)', 'prescription' => 'Pengeluaran resep (retur mengurangi)', 'facility_out' => 'Pengeluaran sarana', 'destruction' => 'Pengeluaran pemusnahan'];

    public static function movements(Pharmacy $pharmacy, string $from, string $to): Builder
    {
        return DB::table('stock_movements as m')->join('products as p', fn ($join) => $join->on('p.id', '=', 'm.product_id')->where('p.pharmacy_id', $pharmacy->id))
            ->leftJoin('psychotropic_movement_categories as c', fn ($join) => $join->on('c.movement_id', '=', 'm.id')->where('c.pharmacy_id', $pharmacy->id))
            ->leftJoin('sale_items as si', 'si.id', '=', 'm.sale_item_id')->leftJoin('sales as s', 's.id', '=', 'si.sale_id')
            ->leftJoin('sale_return_items as ri', 'ri.id', '=', 'm.sale_return_item_id')->leftJoin('sale_items as rsi', 'rsi.id', '=', 'ri.sale_item_id')->leftJoin('sales as rs', 'rs.id', '=', 'rsi.sale_id')
            ->where('m.pharmacy_id', $pharmacy->id)->where('p.is_psychotropic', true)->whereBetween('m.occurred_on', [$from, $to])
            ->select('m.*', 'p.name as product_name', 'p.sku', 's.kind as sale_kind', 'rs.kind as return_kind', 'c.category as manual_category', 'c.reason');
    }

    public static function category(object $movement): ?string
    {
        if ($movement->manual_category) {
            return $movement->manual_category;
        }
        if ($movement->type === 'PURCHASE_RECEIPT') {
            return 'pbf';
        }
        if (($movement->type === 'SALE' && $movement->sale_kind === 'PRESCRIPTION') || ($movement->type === 'SALE_RETURN' && $movement->return_kind === 'PRESCRIPTION')) {
            return 'prescription';
        }

        return null;
    }

    public static function scaled(string $value): int
    {
        return (str_starts_with($value, '-') ? -1 : 1) * Decimal::scaled(ltrim($value, '-'), 4);
    }

    public function build(Pharmacy $pharmacy, int $year, int $month): array
    {
        $date = Carbon::create($year, $month, 1, 0, 0, 0, $pharmacy->timezone);
        $from = $date->toDateString();
        $to = $date->copy()->endOfMonth()->toDateString();
        $products = $pharmacy->products()->where('is_psychotropic', true)->orderBy('name')->orderBy('id')->get(['id', 'name', 'sku', 'base_unit']);
        $opening = DB::table('stock_movements')->where('pharmacy_id', $pharmacy->id)->whereIn('product_id', $products->pluck('id'))->where('occurred_on', '<', $from)
            ->groupBy('product_id')->select('product_id')->selectRaw('SUM(quantity) as total')->pluck('total', 'product_id');
        $rows = [];
        foreach ($products as $product) {
            $initial = self::scaled((string) ($opening[$product->id] ?? 0));
            $rows[$product->id] = ['product' => $product, 'opening' => $initial, 'pbf' => 0, 'facility_in' => 0, 'prescription' => 0, 'facility_out' => 0, 'destruction' => 0, 'closing' => $initial, 'unmapped' => 0];
        }
        $unmapped = 0;
        foreach (self::movements($pharmacy, $from, $to)->orderBy('m.id')->cursor() as $movement) {
            $quantity = self::scaled((string) $movement->quantity);
            $category = self::category($movement);
            $rows[$movement->product_id]['closing'] += $quantity;
            if (! $category) {
                $rows[$movement->product_id]['unmapped']++;
                $unmapped++;
            } else {
                $rows[$movement->product_id][$category] += in_array($category, ['pbf', 'facility_in']) ? $quantity : -$quantity;
            }
        }

        return compact('rows', 'unmapped', 'from', 'to', 'date');
    }

    public function excel(Pharmacy $pharmacy, array $report): string
    {
        $location = DB::table('regions')->whereIn('code', array_filter([$pharmacy->province_code, $pharmacy->city_code]))->orderBy('level')->pluck('name')->implode(', ');
        $rows = [array_fill(0, 12, ''), ['<center><style font-size="14"><b>REKAPITULASI LAPORAN PSIKOTROPIKA</b></style></center>'], [],
            ['', 'Nama Unit Layanan:', '', "\0".$pharmacy->name, '', '', '', '', '', 'Tahun:', $report['date']->year],
            ['', 'Provinsi, Kabupaten/Kota:', '', "\0".$location, '', '', '', '', '', 'Bulan:', "\0".$report['date']->locale('id')->translatedFormat('F')], [],
            ['', 'NO', 'NAMA', 'SATUAN', 'STOK AWAL', 'PEMASUKAN', '', 'PENGELUARAN', '', '', 'STOK AKHIR'],
            ['', '', '', '', '', 'PBF', 'SARANA', 'RESEP', 'SARANA', 'PEMUSNAHAN', '']];
        foreach ([6, 7] as $index) {
            foreach ($rows[$index] as $column => $label) {
                if ($column > 0) {
                    $rows[$index][$column] = '<style border="thin" bgcolor="#EAF0ED"><center><b>'.$label.'</b></center></style>';
                }
            }
        }
        foreach (array_values($report['rows']) as $index => $row) {
            $cells = ['', '<style border="thin">'.($index + 1).'</style>', '<style border="thin"><raw>'.htmlspecialchars($row['product']->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</raw></style>', '<style border="thin"><raw>'.htmlspecialchars($row['product']->base_unit, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</raw></style>'];
            foreach (['opening', 'pbf', 'facility_in', 'prescription', 'facility_out', 'destruction', 'closing'] as $key) {
                $format = $row[$key] % 10000 === 0 ? '#,##0' : '#,##0.####';
                $cells[] = '<style border="thin" nf="'.$format.'">'.($row[$key] / 10000).'</style>';
            }
            $rows[] = $cells;
        }
        $footer = count($rows) + 3;
        $assignment = $pharmacy->assignments()->where('role', 'APJ')->where('starts_on', '<=', $report['to'])->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $report['to']))->orderByDesc('starts_on')->first();
        $identity = $assignment?->credential_snapshot ?? [];
        $rows[] = [];
        $rows[] = [];
        $rows[] = ['', '', '', '', '', '', '', '', 'Apoteker Penanggung Jawab'];
        $rows[] = [];
        $rows[] = [];
        $rows[] = ['', '', '', '', '', '', '', '', "\0".($identity['name'] ?? 'Belum dicatat')];
        $rows[] = ['', '', '', '', '', '', '', '', "\0".('SIPA: '.($identity['sip_number'] ?? 'Belum dicatat'))];
        $book = SimpleXLSXGen::fromArray($rows, 'Psikotropika')->setDefaultFont('Arial')->setDefaultFontSize(10)->freezePanes('E9');
        foreach (['A2:L2', 'B4:C4', 'D4:I4', 'B5:C5', 'D5:I5', 'B7:B8', 'C7:C8', 'D7:D8', 'E7:E8', 'F7:G7', 'H7:J7', 'K7:K8', 'I'.$footer.':K'.$footer, 'I'.($footer + 3).':K'.($footer + 3), 'I'.($footer + 4).':K'.($footer + 4)] as $merge) {
            $book->mergeCells($merge);
        }
        $book->setColWidth('A', 3)->setColWidth('B', 6)->setColWidth('C', 40)->setColWidth('D', 22)->setColWidth('E:K', 16)->setColWidth('L', 3);

        return (string) $book;
    }
}
