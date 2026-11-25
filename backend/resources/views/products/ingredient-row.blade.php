<div class="product-ingredient-row" data-ingredient-row>
    <label>Nama kandungan<input name="ingredients[{{ $index }}][name]" value="{{ $ingredient['name'] ?? '' }}" maxlength="200" placeholder="Nama zat aktif"></label>
    <label>Kekuatan<input type="number" name="ingredients[{{ $index }}][amount]" value="{{ $ingredient['amount'] ?? '' }}" min="0.0001" max="9999999999" step="0.0001" placeholder="250"></label>
    <label>Satuan<select name="ingredients[{{ $index }}][unit]"><option value="">Pilih</option>@foreach(\App\Models\ProductIngredient::STRENGTH_UNITS as $unit)<option value="{{ $unit }}" @selected(($ingredient['unit'] ?? '') === $unit)>{{ $unit }}</option>@endforeach</select></label>
    <label>Per jumlah<input type="number" name="ingredients[{{ $index }}][basis_quantity]" value="{{ $ingredient['basis_quantity'] ?? '' }}" min="0.0001" max="9999999999" step="0.0001" placeholder="1"></label>
    <label>Satuan basis<select name="ingredients[{{ $index }}][basis_unit]"><option value="">Pilih</option>@foreach(\App\Models\Product::UNITS as $unit)<option value="{{ $unit }}" @selected(($ingredient['basis_unit'] ?? '') === $unit)>{{ $unit }}</option>@endforeach</select></label>
    <button class="btn small" type="button" data-remove-ingredient aria-label="Hapus baris kandungan">Hapus baris</button>
    @foreach(['name','amount','unit','basis_quantity','basis_unit'] as $field) @error('ingredients.'.$index.'.'.$field)<small class="proc-error ingredient-error">{{ $message }}</small>@enderror @endforeach
</div>
