<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Services\ProductExcel;
use Illuminate\Http\Request;

class ProductImportController extends Controller
{
    public function index(Pharmacy $pharmacy)
    {
        return view('products.import');
    }

    public function template(Pharmacy $pharmacy, ProductExcel $excel)
    {
        return response($excel->template($pharmacy), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="template-impor-obat.xlsx"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function store(Request $request, Pharmacy $pharmacy, ProductExcel $excel)
    {
        $request->validate(['file' => 'required|file|extensions:xlsx|mimes:xlsx|max:5120']);
        $count = $excel->import($pharmacy, $request->user()->id, $request->file('file'));

        return redirect()->route('products.index', $pharmacy)->with('status', "$count produk berhasil diimpor dari Excel.");
    }
}
