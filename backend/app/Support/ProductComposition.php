<?php

namespace App\Support;

use App\Models\Pharmacy;
use Illuminate\Support\Facades\DB;

class ProductComposition
{
    public static function label(Pharmacy $pharmacy, ?string $id): string
    {
        if (! $id) return 'Tidak tersedia';
        $key = 'product.composition.'.$pharmacy->id;
        $labels = request()->attributes->get($key);
        if ($labels === null) {
            $products = DB::table('products')->where('pharmacy_id', $pharmacy->id)->pluck('generic_name', 'id');
            $ingredients = DB::table('product_ingredients')->join('products', 'products.id', '=', 'product_ingredients.product_id')
                ->where('products.pharmacy_id', $pharmacy->id)->orderBy('product_ingredients.position')
                ->get(['product_ingredients.product_id', 'product_ingredients.name', 'product_ingredients.amount', 'product_ingredients.unit', 'product_ingredients.basis_quantity', 'product_ingredients.basis_unit'])->groupBy('product_id');
            $labels = $products->map(function ($generic, $productId) use ($ingredients) {
                $parts = $ingredients->get($productId, collect())->map(function ($row) {
                    return $row->name.($row->amount !== null ? ' '.(float) $row->amount.' '.$row->unit.' / '.(float) $row->basis_quantity.' '.$row->basis_unit : '');
                });
                return $parts->isNotEmpty() ? $parts->implode(', ') : ($generic ?: 'Belum dicatat');
            })->all();
            request()->attributes->set($key, $labels);
        }
        return $labels[$id] ?? 'Tidak tersedia';
    }
}
