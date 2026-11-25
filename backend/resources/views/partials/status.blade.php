@php
    $statusLabels = ['PENDING'=>'Menunggu persetujuan','APPROVED'=>'Disetujui · belum dicatat','POSTED'=>'Tercatat','CANCELLED'=>'Dibatalkan','DRAFT'=>'Draft','PAID'=>'Lunas','OPEN'=>'Terbuka','CLOSED'=>'Ditutup','ACTIVE'=>'Aktif','INACTIVE'=>'Nonaktif','Recorded'=>'Tercatat','Verified'=>'Diperiksa internal','Expired'=>'Kedaluwarsa'];
    $statusTone = match($status) {
        'CANCELLED','INACTIVE','Expired' => 'negative',
        'PENDING','APPROVED','OPEN','COUNTING','PARTIAL','WAITING','IN_PROGRESS' => 'attention',
        'POSTED','PAID','CLOSED','ACTIVE','Verified','COMPLETE','COMPLETED','FINAL' => 'positive',
        default => 'neutral',
    };
@endphp
<span class="badge status-{{ $statusTone }}">{{ $statusLabel ?? $statusLabels[$status] ?? $status }}</span>
