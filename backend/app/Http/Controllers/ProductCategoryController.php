<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Services\AccessManager;
use App\Support\TableSort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductCategoryController extends Controller
{
    public function index(Request $request, Pharmacy $pharmacy)
    {
        $data = $request->validate(['q' => 'nullable|string|max:50', 'edit' => 'nullable|integer']);
        $query = DB::table('product_categories')->where('pharmacy_id', $pharmacy->id);
        $editing = isset($data['edit']) ? (clone $query)->where('id', $data['edit'])->firstOrFail() : null;
        if ($term = trim($data['q'] ?? '')) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%']);
        }
        TableSort::apply($query, ['name' => 'name', 'active' => 'active'], 'name');
        return view('products.categories', ['records' => $query->orderBy('id')->paginate(15)->withQueryString(), 'editing' => $editing]);
    }

    public function save(Request $request, Pharmacy $pharmacy, AccessManager $manager)
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate(['id' => 'nullable|integer', 'name' => 'required|string|max:50', 'active' => 'required|boolean']);
        $manager->locked($pharmacy, function () use ($request, $pharmacy, $manager, $data) {
            $manager->actor($pharmacy, $request->user()->id, 'drugs.update');
            $query = DB::table('product_categories')->where('pharmacy_id', $pharmacy->id);
            $record = ! empty($data['id']) ? (clone $query)->where('id', $data['id'])->lockForUpdate()->firstOrFail() : null;
            if ((clone $query)->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])->when($record, fn ($q) => $q->where('id', '!=', $record->id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Nama kategori sudah digunakan di apotek ini.']);
            }
            if ($record) {
                (clone $query)->where('id', $record->id)->update(['name' => $data['name'], 'active' => $data['active']]);
                if ($record->name !== $data['name']) {
                    $pharmacy->products()->where('category', $record->name)->update(['category' => $data['name']]);
                }
                $id = $record->id;
            } else {
                $id = DB::table('product_categories')->insertGetId(['pharmacy_id' => $pharmacy->id, 'name' => $data['name'], 'active' => $data['active']]);
            }
            $manager->audit($pharmacy, $request->user()->id, 'product_category.saved', (string) $id, ['before' => $record ? (array) $record : null, 'after' => $data]);
        });
        return redirect()->route('product-categories.index', $pharmacy)->with('status', 'Kategori obat disimpan.');
    }
}
