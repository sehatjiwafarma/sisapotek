@php
    $sidebarPharmacy = $currentPharmacy ?? null;
    $sidebarAccess = $access ?? null;
    $navigationGroups = [
        'apotek' => [
            'label' => 'Ringkasan', 'tenant' => true,
            'items' => [
                ['route' => 'pharmacy.home', 'label' => 'Beranda apotek', 'match' => 'pharmacy.home', 'permission' => null, 'icon' => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z'],
            ],
        ],
        'master' => [
            'label' => 'Master data', 'tenant' => true,
            'items' => [
                ['route'=>'master.show','label' => 'Profil apotek','match'=>'master.*','permission'=>'pharmacy.view','icon'=>'M3 21h18 M5 21V7l7-4 7 4v14 M9 21v-7h6v7 M10 8h4 M12 6v4'],
                ['route' => 'products.index', 'label' => 'Obat & produk', 'match' => 'products.*', 'permission' => 'drugs.view', 'icon' => 'm9 15 6-6 M5 19a5 5 0 0 1 0-7l7-7a5 5 0 0 1 7 7l-7 7a5 5 0 0 1-7 0Z'],
                ['route' => 'product-categories.index', 'label' => 'Kategori obat', 'match' => 'product-categories.*', 'permission' => 'drugs.view', 'icon' => 'M3 5h18v14H3z M3 10h18 M9 10v9'],
                ['route' => 'suppliers.index', 'label' => 'Supplier', 'match' => 'suppliers.*', 'permission' => 'suppliers.view', 'icon'=>'M3 21h18 M5 21V9l7-5 7 5v12 M9 21v-6h6v6 M9 11h.01 M15 11h.01'],
                ['route'=>'patients.index','label' => 'Pasien & rekam medis','match'=>['patients.index','patients.show','patients.create','patients.edit'],'permission'=>'patients.view','icon'=>'M16 21v-3a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v3 M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M17 8h6 M20 5v6'],
                ['route'=>'doctors.index','label'=>'Dokter & tanda tangan','match'=>'doctors.*','permission'=>'doctors.view','icon'=>'M8 3v5a4 4 0 0 0 8 0V3 M6 3h4 M14 3h4 M12 12v4a4 4 0 0 0 8 0v-2 M20 10a2 2 0 1 0 0 4 2 2 0 0 0 0-4'],
                ['route'=>'operations.catalog','parameters'=>['kind'=>'tariffs'],'label' => 'Layanan & tarif','match'=>'operations.catalog*','permission'=>['billing.tariffs','billing.view'],'icon'=>'M4 4h16v16H4z M8 8h8 M8 12h5 M8 16h3 M17 14v4 M15 16h4'],
                ['route'=>'pricing.index','parameters'=>['kind'=>'discount'],'label' => 'Diskon','match'=>'pricing.*','permission'=>'discounts.view','icon'=>'M4 4h7l9 9-7 7-9-9V4Z M8 8h.01'],
                ['route'=>'pricing.index','parameters'=>['kind'=>'tax'],'label' => 'PPN','match'=>'pricing.*','permission'=>'taxes.view','icon'=>'M5 3h14v18H5z M8 8h.01 M16 16h.01 M16 8l-8 8'],
                ['route'=>'operations.catalog','parameters'=>['kind'=>'categories'],'label'=>'Kategori pengeluaran','match'=>'operations.catalog*','permission'=>['expenses.categories','expenses.view'],'icon'=>'M3 5h18v14H3z M3 10h18 M9 10v9'],
            ],
        ],
        'clinical' => [
            'label' => 'Pelayanan pasien', 'tenant' => true,
            'items' => [
                ['route'=>'visits.index','label'=>'Kunjungan & antrean','match'=>'visits.*','permission'=>'visits.view','icon'=>'M5 4h14v17H5z M9 2v4 M15 2v4 M8 11h8 M8 15h5'],
                ['route'=>'examinations.index','label' => 'Pemeriksaan','match'=>['examinations.*','soap-tasks.*','prescriptions.*'],'permission'=>'examinations.view','icon'=>'M9 4h6 M9 3h6v3H9z M8 4H5v17h14V4h-3 M8 11h2l1-2 2 5 1-3h2'],
                ['route'=>'patients.follow-up','label'=>'Konsultasi kembali','match'=>'patients.follow-up','permission'=>'dispensing.view','icon'=>'M20 7v5h-5 M19 12a7 7 0 1 1-2-5'],
                ['route'=>'combined-dispensing.index','label'=>'Penebusan gabungan','match'=>'combined-dispensing.*','permission'=>'dispensing.view','icon'=>'M5 3h14v18H5z M8 8h8 M8 12h8 M8 16h8'],
                ['route'=>'dispensing.index','label'=>'Penebusan resep','match'=>'dispensing.*','permission'=>'dispensing.view','icon'=>'M5 3h14v18H5z M8 8h8 M8 12h3 M14 11v5 M11.5 13.5h5 M8 17h2'],
            ],
        ],
        'sales' => [
            'label' => 'Penjualan & kasir', 'tenant' => true,
            'items' => [
                ['route' => 'cashier.index', 'label' => 'Kasir / pembayaran', 'match' => ['cashier.*', 'sales.*', 'billing.*'], 'permission' => ['sales.view', 'sales.create', 'billing.view', 'dispensing.view'], 'icon' => 'M3 3h2l3 12h10l3-9H6 M9 21h.01 M18 21h.01 M8 18h11'],
                ['route'=>'returns.index','label'=>'Retur pelanggan & refund','match'=>'returns.*','permission'=>'returns.view','icon'=>'M9 4 4 9l5 5 M4 9h10a6 6 0 0 1 0 12'],
                ['route'=>'shifts.index','label'=>'Shift & tutup kas','match'=>'shifts.*','permission'=>'shifts.view','icon'=>'M4 7h16v13H4z M7 4h10v3 M8 11h8 M8 15h3 M16 15h.01'],
            ],
        ],
        'procurement' => [
            'label' => 'Pembelian supplier', 'tenant' => true,
            'items' => [
                ['route' => 'purchases.index', 'label' => 'Pesanan pembelian', 'match' => ['purchases.*', 'receipts.*'], 'permission' => 'purchases.view', 'icon'=>'M4 5h2l2 10h10l2-7H7 M9 19h.01 M17 19h.01 M14 3v6 M11 6h6'],
                ['route'=>'supplier-returns.index','label'=>'Retur ke supplier','match'=>'supplier-returns.*','permission'=>'supplier_returns.view','icon'=>'M9 4 4 9l5 5 M4 9h10a6 6 0 0 1 0 12'],
            ],
        ],
        'inventory' => [
            'label' => 'Persediaan', 'tenant' => true,
            'items' => [
                ['route' => 'inventory.index', 'label' => 'Stok & kartu stok', 'match' => 'inventory.*', 'permission' => 'inventory.view', 'icon' => 'm3 7 9-4 9 4v10l-9 4-9-4V7Z M3 7l9 4 9-4 M12 11v10'],
                ['route'=>'stocktakes.index','label'=>'Stok opname','match'=>'stocktakes.*','permission'=>'stocktakes.view','icon'=>'M9 3h6v4H9z M9 5H5v16h14V5h-4 M8 12l2 2 5-5 M8 18h8'],
            ],
        ],
        'finance' => [
            'label' => 'Keuangan & laporan', 'tenant' => true,
            'items' => [
                ['route'=>'payables.index','label'=>'Utang & pembayaran','match'=>'payables.*','permission'=>'payables.view','icon'=>'M4 5h16v14H4z M4 9h16 M8 15h3 M16 13v4 M14 15h4'],
                ['route'=>'expenses.index','label'=>'Pengeluaran operasional','match'=>'expenses.*','permission'=>'expenses.view','icon'=>'M4 5h16v14H4z M4 9h16 M8 15h3 M14 15h4'],
                ['route'=>'reports.index','label'=>'Laporan operasional','match'=>'reports.*','permission'=>['reports.sales','reports.purchases','reports.inventory','reports.finance'],'icon'=>'M4 20V10 M10 20V4 M16 20v-7 M22 20H2'],
                ['route'=>'psychotropic.index','label'=>'Rekap psikotropika','match'=>'psychotropic.*','permission'=>'reports.inventory','icon'=>'M5 3h14v18H5z M8 8h8 M8 12h3 M8 16h8 M15 11v3 M13.5 12.5h3'],
                ['route'=>'profit-loss.index','label'=>'Laporan laba rugi','match'=>'profit-loss.*','permission'=>null,'owner'=>true,'icon'=>'M4 19h16 M6 15l4-4 3 2 5-6 M14 7h4v4'],
            ],
        ],
        'access' => [
            'label' => 'Pengguna & hak akses', 'tenant' => true,
            'items' => [
                ['route' => 'users.index', 'label' => 'Pengguna', 'match' => 'users.*', 'permission' => 'users.view', 'icon' => 'M16 21v-3a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v3 M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M17 3a4 4 0 0 1 0 8 M22 21v-3a4 4 0 0 0-3-4'],
                ['route' => 'roles.index', 'label' => 'Role & izin', 'match' => 'roles.*', 'permission' => 'roles.view', 'icon' => 'm12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z M8 12l3 3 5-6'],
                ['route' => 'audit.index', 'label' => 'Audit aktivitas', 'match' => 'audit.*', 'permission' => 'audit.view', 'icon' => 'M5 3h10l4 4v14H5z M14 3v5h5 M8 12h8 M8 16h8'],
            ],
        ],
        'system' => [
            'label' => 'Administrasi sistem', 'tenant' => false, 'admin' => true,
            'items' => [
                ['route'=>'system.pharmacies','label'=>'Master seluruh apotek','match'=>'system.*','permission'=>null,'icon'=>'m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z M8 12l3 3 5-6'],
            ],
        ],
        'account' => [
            'label' => 'Workspace & akun', 'tenant' => false,
            'items' => [
                ['route' => 'pharmacies.index', 'label' => 'Daftar apotek', 'match' => 'pharmacies.*', 'permission' => null, 'icon' => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z'],
                ['route' => 'profile.edit', 'label' => 'Akun saya', 'match' => 'profile.*', 'permission' => null, 'icon' => 'M20 21v-2a7 7 0 0 0-14 0v2 M17 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0'],
            ],
        ],
    ];
@endphp

<nav class="sidebar-nav" id="sidebar-navigation" aria-label="Navigasi utama" data-preference-scope="{{ auth()->id() }}:{{ $sidebarPharmacy?->id ?? 'account' }}">
    @foreach($navigationGroups as $groupKey => $group)
        @php
            $visibleItems = collect($group['items'])->filter(function ($item) use ($group, $sidebarPharmacy, $sidebarAccess) {
                if (($group['admin'] ?? false) && !auth()->user()?->is_system_admin) { return false; }
                if (($item['owner'] ?? false) && !$sidebarAccess?->isOwner()) { return false; }
                if ($group['tenant'] && !$sidebarPharmacy) {
                    return false;
                }

                return auth()->check() && (!$item['permission'] || ($item['permission']==='pharmacy.view' && auth()->user()->is_system_admin) || collect((array) $item['permission'])->contains(fn ($permission) => $sidebarAccess?->allows($permission)));
            });
        @endphp
        @if($visibleItems->isNotEmpty())
            <section class="sidebar-group" aria-labelledby="sidebar-group-{{ $groupKey }}" @if(!in_array($groupKey, ['apotek', 'account'])) data-sidebar-group="{{ $groupKey }}" @endif>
                <h2 class="sidebar-group-title" id="sidebar-group-{{ $groupKey }}">
                    @if(!in_array($groupKey, ['apotek', 'account']))
                    <button type="button" class="sidebar-group-toggle" aria-expanded="true" aria-controls="sidebar-items-{{ $groupKey }}">
                        <span>{{ $group['label'] }}</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    @else
                    {{ $group['label'] }}
                    @endif
                </h2>
                <div class="sidebar-group-items" id="sidebar-items-{{ $groupKey }}">
                    @foreach($visibleItems as $item)
                        @php($isActive = request()->routeIs(...(array) $item['match']) && (!isset($item['parameters']['kind']) || request()->route('kind') === $item['parameters']['kind']))
                        <a class="{{ $isActive ? 'active' : '' }}" href="{{ $group['tenant'] ? route($item['route'], ['pharmacy'=>$currentPharmacy]+($item['parameters'] ?? [])) : route($item['route']) }}" aria-label="{{ $item['label'] }}" title="{{ $item['label'] }}" @if($isActive) aria-current="page" @endif>
                            <svg class="sidebar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $item['icon'] }}"/></svg>
                            <span>{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach
</nav>
