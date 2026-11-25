<dialog class="app-confirm" id="app-confirm" aria-labelledby="app-confirm-title" aria-describedby="app-confirm-message">
    <div class="app-confirm-body">
        <span class="app-confirm-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v6m0 3v1"/></svg></span>
        <h2 id="app-confirm-title">Konfirmasi tindakan</h2>
        <p id="app-confirm-message"></p>
    </div>
    <div class="app-confirm-actions">
        <button type="button" class="btn" data-dialog-cancel>Batal</button>
        <button type="button" class="btn primary" data-dialog-accept>Lanjutkan</button>
    </div>
</dialog>
<link rel="stylesheet" href="{{ asset('css/confirmation.css') }}?v={{ filemtime(public_path('css/confirmation.css')) }}">
<script src="{{ asset('js/confirmation.js') }}?v={{ filemtime(public_path('js/confirmation.js')) }}" defer></script>
