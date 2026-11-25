<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Models\Product;
use App\Services\AccessManager;
use App\Services\ProductInput;
use App\Services\Stocktaking;
use App\Support\Decimal;
use App\Support\TableSort;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request, Pharmacy $pharmacy)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:150', 'category' => 'nullable|string|max:50', 'status' => ['nullable', Rule::in(['active', 'inactive'])]]);
        $query = $pharmacy->products()->with('ingredients');
        if ($term = trim($filters['q'] ?? '')) {
            $query->where(function ($query) use ($term) {
                $query->orWhereHas('ingredients', fn ($ingredients) => $ingredients->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%']));
                foreach (['name', 'brand', 'generic_name', 'sku', 'barcode'] as $column) {
                    $query->orWhereRaw('LOWER('.$column.') LIKE ?', ['%'.mb_strtolower($term).'%']);
                }
            });
        }
        $query->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category));
        if ($filters['status'] ?? null) {
            $query->where('active', $filters['status'] === 'active');
        }

        TableSort::apply($query, ['name' => 'name', 'brand' => 'brand', 'category' => 'category', 'price' => 'selling_price',
            'status' => fn ($q, $d) => $q->orderBy('active', $d === 'asc' ? 'desc' : 'asc')], 'name');

        return view('products.index', ['products' => $query->orderBy('id')->paginate(15)->withQueryString(),
            'categories' => DB::table('product_categories')->where('pharmacy_id', $pharmacy->id)->orderBy('name')->pluck('name'),
            'counts' => ['all' => $pharmacy->products()->count(), 'active' => $pharmacy->products()->where('active', true)->count(), 'inactive' => $pharmacy->products()->where('active', false)->count()]]);
    }

    public function create(Pharmacy $pharmacy)
    {
        return view('products.form', ['categories' => DB::table('product_categories')->where('pharmacy_id', $pharmacy->id)->where('active', true)->orderBy('name')->pluck('name'), 'product' => new Product(['active' => true, 'requires_prescription' => false, 'purchase_conversion' => 1, 'minimum_stock' => 0])]);
    }

    public function edit(Request $request, Pharmacy $pharmacy, string $product)
    {
        $product = $pharmacy->products()->with('ingredients')->findOrFail($product);
        $canSeePurchaseSource = $request->attributes->get('membership')->allows('purchases.view');
        $source = $canSeePurchaseSource ? DB::table('purchase_receipt_items as ri')->join('purchase_receipts as r', 'r.id', '=', 'ri.purchase_receipt_id')
            ->join('purchase_order_items as oi', 'oi.id', '=', 'ri.purchase_order_item_id')
            ->join('purchase_orders as po', 'po.id', '=', 'r.purchase_order_id')
            ->where('r.pharmacy_id', $pharmacy->id)->where('r.status', 'POSTED')->where('oi.product_id', $product->id)
            ->select('r.number', 'r.received_on', 'po.supplier_name', 'ri.unit_price', 'oi.conversion')
            ->orderByDesc('r.received_on')->get()->sortByDesc(fn ($row) => (float) $row->unit_price / (float) $row->conversion)->first() : null;
        $sourceMatches = $source && $product->purchase_price !== null && number_format((float) $source->unit_price / (float) $source->conversion * (float) $product->purchase_conversion, 2, '.', '') === $product->purchase_price;
        return view('products.form', ['categories' => DB::table('product_categories')->where('pharmacy_id', $pharmacy->id)->where(fn ($q) => $q->where('active', true)->orWhere('name', $product->category))->orderBy('name')->pluck('name'), 'product' => $product, 'purchasePriceSource' => $sourceMatches ? $source : null, 'canSeePurchaseSource' => $canSeePurchaseSource]);
    }

    public function store(Request $request, Pharmacy $pharmacy, AccessManager $manager)
    {
        return $this->save($request, $pharmacy, $manager);
    }

    public function update(Request $request, Pharmacy $pharmacy, string $product, AccessManager $manager)
    {
        $pharmacy->products()->findOrFail($product);

        return $this->save($request, $pharmacy, $manager, $product);
    }

    private function save(Request $request, Pharmacy $pharmacy, AccessManager $manager, ?string $id = null)
    {
        $data = ProductInput::validate($request, $pharmacy, $id);
        try {
            $manager->locked($pharmacy, function () use ($data, $id, $pharmacy, $manager, $request) {
                $manager->actor($pharmacy, $request->user()->id, $id ? 'drugs.update' : 'drugs.create');
                $product = $id ? $pharmacy->products()->lockForUpdate()->findOrFail($id) : $pharmacy->products()->make();
                $before = $product->exists ? array_merge($product->getAttributes(), ['ingredients' => $product->ingredients->toArray()]) : null;
                $replaceIngredients = array_key_exists('ingredients', $data) || isset($data['ingredients_present']);
                $ingredients = $data['ingredients'] ?? [];
                unset($data['ingredients'], $data['ingredients_present']);
                unset($data['name']);
                if (! $id) {
                    $data['name'] = $data['brand'];
                }
                $product->fill($data);
                if ($id && array_key_exists('selling_units', $data)) {
                    foreach ($product->getOriginal('selling_units') ?? [] as $index => $unit) {
                        $replacement = $data['selling_units'][$index] ?? null;
                        if ((! $replacement || $unit['unit'] !== $replacement['unit'] || Decimal::scaled($unit['conversion'], 4) !== Decimal::scaled($replacement['conversion'], 4))
                            && DB::table('sale_items')->where('product_id', $id)->where('unit_type', 's'.$index)->exists()) {
                            throw ValidationException::withMessages(['selling_units' => 'Satuan jual yang sudah digunakan tidak dapat dihapus, dipindah urutan, atau diubah konversinya. Harga tetap dapat diubah.']);
                        }
                    }
                }
                if ($id && $product->isDirty(['base_unit', 'purchase_unit', 'purchase_conversion'])) {
                    Stocktaking::assertAvailable($pharmacy, [$id]);
                    if (DB::table('stock_movements')->where('pharmacy_id', $pharmacy->id)->where('product_id', $id)->exists()) {
                        throw ValidationException::withMessages(['base_unit' => 'Satuan dan konversi sudah dipakai dalam persediaan. Buat produk baru untuk kemasan yang berbeda.']);
                    }
                }
                if ($id && $product->isDirty(['base_unit', 'purchase_unit', 'purchase_conversion']) && DB::table('purchase_order_items')->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')->where('purchase_order_items.product_id', $id)->whereNotNull('purchase_orders.finalized_at')->exists()) {
                    throw ValidationException::withMessages(['base_unit' => 'Satuan dan konversi sudah dipakai pada PO yang dikonfirmasi. Buat produk baru untuk kemasan yang berbeda.']);
                }
                if (! $id) {
                    $product->active = true;
                }
                $product->save();
                if ($replaceIngredients) {
                    $product->ingredients()->delete();
                    foreach (array_values($ingredients) as $index => $ingredient) {
                        $product->ingredients()->create(array_merge($ingredient, ['position' => $index]));
                    }
                }
                $manager->audit($pharmacy, $request->user()->id, $id ? 'product.updated' : 'product.created', $product->id, ['before' => $before, 'after' => array_merge($product->getAttributes(), ['ingredients' => $product->ingredients()->get()->toArray()])]);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['sku' => 'SKU atau barcode sudah digunakan di apotek ini.']);
        }

        return redirect()->route('products.index', $pharmacy)->with('status', 'Data obat & produk disimpan.');
    }

    public function status(Request $request, Pharmacy $pharmacy, string $product, AccessManager $manager)
    {
        $data = $request->validate(['active' => 'required|boolean']);
        $manager->locked($pharmacy, function () use ($request, $pharmacy, $product, $manager, $data) {
            $manager->actor($pharmacy, $request->user()->id, 'drugs.deactivate');
            $record = $pharmacy->products()->lockForUpdate()->findOrFail($product);
            $record->active = $request->boolean('active');
            $record->save();
            $manager->audit($pharmacy, $request->user()->id, 'product.status_changed', $record->id, $data);
        });

        return back()->with('status', 'Status produk diperbarui.');
    }
}
