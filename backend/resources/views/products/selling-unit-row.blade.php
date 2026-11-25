<fieldset class="product-selling-row" data-selling-unit-row><legend>Kemasan jual</legend>
<label>Satuan *<select name="selling_units[{{ $index }}][unit]" required><option value="">Pilih satuan</option>@foreach(\App\Models\Product::UNITS as $option)<option value="{{ $option }}" @selected(($unit['unit'] ?? '') === $option)>{{ $option }}</option>@endforeach</select></label>
<label>Isi dalam satuan dasar *<input name="selling_units[{{ $index }}][conversion]" type="number" value="{{ $unit['conversion'] ?? '' }}" required min="0.0001" max="10000" step="0.0001"></label>
<label>Harga jual (Rp / satuan ini) *<input name="selling_units[{{ $index }}][price]" type="number" value="{{ $unit['price'] ?? '' }}" required min="0" max="10000000" step="0.01"></label>
<button type="button" class="btn danger" data-selling-unit-remove>Hapus satuan</button>
</fieldset>
