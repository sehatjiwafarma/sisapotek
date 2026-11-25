<section class="card product-form-section" data-ingredients-editor>
    <header><span class="product-section-number">02</span><div><h2>Komposisi / kandungan <x-help-tooltip label="Informasi Komposisi / kandungan">Tambahkan setiap zat aktif, termasuk untuk obat kombinasi. Stok tetap dicatat per produk.</x-help-tooltip></h2></div></header>
    <div class="product-ingredients-content">
        <input type="hidden" name="ingredients_present" value="1">
        @if($product->generic_name || $product->strength)
            <div class="notice">Referensi data lama: {{ $product->generic_name }} {{ $product->strength }}. Lengkapi komposisi terstruktur sesuai kemasan; data lama tidak diterjemahkan otomatis.</div>
        @endif
        <p class="muted">Kekuatan diisi lengkap atau dikosongkan jika belum diketahui. Contoh format: 250 mg per 1 Tablet, atau 125 mg per 5 ml.</p>
        @error('ingredients')<small class="proc-error">{{ $message }}</small>@enderror
        <div data-ingredient-rows>
            @foreach(old('ingredients', $product->ingredients->map->only(['name','amount','unit','basis_quantity','basis_unit'])->all()) ?: [[]] as $index => $ingredient)
                @include('products.ingredient-row', ['index'=>$index, 'ingredient'=>$ingredient])
            @endforeach
        </div>
        <button type="button" class="btn" data-add-ingredient>+ Tambah kandungan</button>
        <span role="status" data-ingredient-status></span>
        <noscript><p>Aktifkan JavaScript untuk menambah atau menghapus baris kandungan.</p></noscript>
        <template data-ingredient-template>@include('products.ingredient-row', ['index'=>'__INDEX__', 'ingredient'=>[]])</template>
    </div>
</section>
@push('master-scripts')<script src="{{ asset('js/product-ingredients.js') }}?v={{ filemtime(public_path('js/product-ingredients.js')) }}" defer></script>@endpush
