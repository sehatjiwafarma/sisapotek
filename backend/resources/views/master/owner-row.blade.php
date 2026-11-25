<fieldset data-owner-row class="card padded">
<legend>Pemilik</legend>
<div class="master-fields">
<label>Nama pemilik *<input name="owners[{{ $index }}][name]" value="{{ $owner['name'] ?? '' }}" required maxlength="150"></label>
<label>Telepon pemilik *<input type="tel" name="owners[{{ $index }}][phone]" value="{{ $owner['phone'] ?? '' }}" required maxlength="20"></label>
<label>Email pemilik<input type="email" name="owners[{{ $index }}][email]" value="{{ $owner['email'] ?? '' }}" maxlength="254"></label>
<label>Saham (%) *<input type="number" name="owners[{{ $index }}][share_percentage]" value="{{ $owner['share_percentage'] ?? '' }}" min="0.01" max="100" step="0.01" required></label>
</div>
<button type="button" class="btn" data-owner-remove>Hapus pemilik</button>
</fieldset>
