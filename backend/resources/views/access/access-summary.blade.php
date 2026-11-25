@php
    $permissionGroups = \App\Support\Permissions::groups();
    $accessCategories = [
        'Pengelolaan apotek' => ['users', 'roles', 'pharmacy', 'audit', 'dashboard', 'settings'],
        'Master data' => ['drugs', 'suppliers', 'patients', 'doctors'],
        'Pembelian & persediaan' => ['purchases', 'receipts', 'payables', 'supplier_returns', 'inventory', 'stocktakes'],
        'Layanan pasien & farmasi' => ['visits', 'examinations', 'prescriptions', 'dispensing', 'billing'],
        'Penjualan & laporan' => ['sales', 'returns', 'shifts', 'expenses', 'reports'],
    ];
    $visibleCategories = [];
    $moduleCount = 0;
    $actionCount = 0;
    foreach ($accessCategories as $category => $modules) {
        foreach ($modules as $module) {
            [$moduleLabel, $actions] = $permissionGroups[$module];
            $allowedActions = array_filter($actions, fn ($action) => $access->allows($module.'.'.$action), ARRAY_FILTER_USE_KEY);
            if ($allowedActions) {
                $visibleCategories[$category][] = ['label' => $moduleLabel, 'actions' => $allowedActions];
                $moduleCount++;
                $actionCount += count($allowedActions);
            }
        }
    }
@endphp

<section class="card access-overview" aria-labelledby="access-overview-title">
    <div class="access-overview-header">
        <div class="access-overview-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z M8 12l3 3 5-6"/></svg>
        </div>
        <div class="access-overview-copy">
            <h2 id="access-overview-title">{{ $access->isOwner() ? 'Anda memiliki akses penuh' : ($actionCount ? 'Akses sesuai peran Anda' : 'Belum ada akses fitur') }}</h2>
            <p>{{ $access->isOwner() ? 'Sebagai Owner, Anda dapat mengelola seluruh fitur di apotek ini.' : ($actionCount ? 'Berikut akses yang tersedia dari gabungan role Anda di apotek ini.' : 'Hubungi pengelola apotek untuk mendapatkan izin yang dibutuhkan.') }}</p>
        </div>
        @if($actionCount)
            <div class="access-overview-total"><strong>{{ $moduleCount }} modul</strong><span>{{ $actionCount }} aksi diizinkan</span></div>
        @endif
    </div>

    @if($actionCount)
        <details class="access-breakdown">
            <summary><span>Lihat rincian akses per modul</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
            <div class="access-category-grid">
                @foreach($visibleCategories as $category => $modules)
                    <section class="access-category" aria-labelledby="access-category-{{ $loop->index }}">
                        <h3 id="access-category-{{ $loop->index }}">{{ $category }}<span>{{ count($modules) }} modul</span></h3>
                        <dl class="access-module-list">
                            @foreach($modules as $module)
                                <div class="access-module-row">
                                    <dt>{{ $module['label'] }}</dt>
                                    <dd><ul>@foreach($module['actions'] as $action)<li>{{ $action }}</li>@endforeach</ul></dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                @endforeach
            </div>
            <p class="access-overview-note">Daftar ini mencakup izin untuk modul operasional yang akan tersedia pada tahap pengembangan berikutnya.</p>
        </details>
    @endif
</section>
