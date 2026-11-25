<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\ProductIngredient;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductInput
{
    public static function validate(Request $request, Pharmacy $pharmacy, ?string $id = null): array
    {
        $request->merge(collect($request->only(['sku', 'barcode', 'name', 'brand', 'generic_name', 'strength', 'notes']))
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)->all());
        if (is_string($request->input('sku'))) {
            $request->merge(['sku' => mb_strtoupper($request->input('sku'))]);
        }
        if (is_array($request->input('ingredients'))) {
            $request->merge(['ingredients' => collect($request->input('ingredients'))->map(function ($row) {
                return is_array($row) ? array_map(fn ($value) => is_string($value) ? trim($value) : $value, $row) : $row;
            })->filter(fn ($row) => ! is_array($row) || collect($row)->contains(fn ($value) => $value !== null && $value !== ''))->all()]);
        }
        $unique = fn ($column) => Rule::unique('products', $column)->where('pharmacy_id', $pharmacy->id)->ignore($id);
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:50', $unique('sku')], 'barcode' => ['nullable', 'string', 'max:100', $unique('barcode')],
            'name' => 'nullable|string|max:200', 'brand' => 'required|string|max:150', 'generic_name' => 'nullable|string|max:200',
            'category' => ['required', Rule::exists('product_categories', 'name')->where(fn ($q) => $q->where('pharmacy_id', $pharmacy->id)->where(fn ($q) => $q->where('active', true)->when($id, fn ($q) => $q->orWhere('name', $pharmacy->products()->whereKey($id)->value('category')))))], 'dosage_form' => ['nullable', Rule::in(Product::FORMS)], 'strength' => 'nullable|string|max:100',
            'base_unit' => ['required', Rule::in(Product::UNITS)], 'purchase_unit' => ['required', Rule::in(Product::UNITS)],
            'purchase_conversion' => 'required|numeric|gt:0|max:9999999999|decimal:0,4',
            'purchase_price' => 'nullable|numeric|min:0|max:99999999999999|decimal:0,2', 'selling_price' => 'required|numeric|min:0|max:99999999999999|decimal:0,2',
            'minimum_stock' => 'required|numeric|min:0|max:9999999999|decimal:0,4', 'maximum_stock' => 'nullable|numeric|gte:minimum_stock|max:9999999999|decimal:0,4',
            'ingredients_present' => 'sometimes|accepted', 'ingredients' => 'sometimes|array|max:20',
            'ingredients.*' => 'array:name,amount,unit,basis_quantity,basis_unit',
            'ingredients.*.name' => 'required|string|max:200|distinct:ignore_case',
            'ingredients.*.amount' => 'nullable|numeric|gt:0|max:9999999999|decimal:0,4',
            'ingredients.*.unit' => ['nullable', Rule::in(ProductIngredient::STRENGTH_UNITS)],
            'ingredients.*.basis_quantity' => 'nullable|numeric|gt:0|max:9999999999|decimal:0,4',
            'ingredients.*.basis_unit' => ['nullable', Rule::in(Product::UNITS)],
            'requires_prescription' => 'required|boolean', 'is_psychotropic' => 'sometimes|boolean', 'notes' => 'nullable|string|max:2000',
            'selling_units_present' => 'sometimes|accepted',
            'selling_units' => 'sometimes|array|list|max:20',
            'selling_units.*' => 'required|array:unit,conversion,price',
            'selling_units.*.unit' => ['required', 'distinct', Rule::in(Product::UNITS)],
            'selling_units.*.conversion' => 'required|numeric|gt:0|max:10000|decimal:0,4',
            'selling_units.*.price' => 'required|numeric|min:0|max:10000000|decimal:0,2',
        ], [], ['name' => 'nama produk', 'brand' => 'brand', 'sku' => 'kode SKU', 'purchase_conversion' => 'isi kemasan', 'maximum_stock' => 'stok maksimum']);
        foreach ($data['ingredients'] ?? [] as $index => $ingredient) {
            $strengthFields = collect($ingredient)->only(['amount', 'unit', 'basis_quantity', 'basis_unit']);
            if ($strengthFields->contains(fn ($value) => $value !== null && $value !== '') && $strengthFields->filter(fn ($value) => $value !== null && $value !== '')->count() !== 4) {
                throw ValidationException::withMessages(['ingredients.'.$index.'.amount' => 'Lengkapi kekuatan, satuan, jumlah basis, dan satuan basis; atau kosongkan keempatnya.']);
            }
        }
        foreach ($data['selling_units'] ?? [] as $index => $unit) {
            if ($unit['unit'] === $data['base_unit']) {
                throw ValidationException::withMessages(['selling_units.'.$index.'.unit' => 'Gunakan harga satuan dasar untuk satuan ini.']);
            }
            if ($unit['unit'] === $data['purchase_unit'] && Decimal::scaled($unit['conversion'], 4) !== Decimal::scaled($data['purchase_conversion'], 4)) {
                throw ValidationException::withMessages(['selling_units.'.$index.'.conversion' => 'Isi satuan jual ini harus sama dengan isi satuan pembelian yang bernama sama.']);
            }
        }
        if (isset($data['selling_units_present'])) {
            $data['selling_units'] ??= [];
        }
        unset($data['selling_units_present']);
        if ($data['base_unit'] === $data['purchase_unit'] && (float) $data['purchase_conversion'] !== 1.0) {
            throw ValidationException::withMessages(['purchase_conversion' => 'Jika satuan pembelian sama dengan satuan dasar, isi kemasan harus 1.']);
        }

        return $data;
    }
}
