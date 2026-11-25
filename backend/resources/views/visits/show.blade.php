<?php $__env->startSection('title','Detail kunjungan'); ?>
<?php echo $__env->make('clinical.assets', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php $__env->startPush('styles'); ?><link rel="stylesheet" href="<?php echo e(asset('css/visits.css')); ?>?v=<?php echo e(filemtime(public_path('css/visits.css'))); ?>"><?php $__env->stopPush(); ?>
<?php $__env->startSection('content'); ?>
<?php ($visitTimezone = $currentPharmacy->settings?->timezone ?: 'Asia/Jakarta'); ?>
<div class="products-page visit-detail-page">
    <div class="heading">
        <div><span class="eyebrow">LAYANAN PASIEN / KUNJUNGAN</span><h1><?php echo e($visit->number); ?></h1><p><?php echo e(\Illuminate\Support\Carbon::parse($visit->registered_at)->timezone($visitTimezone)->format('d/m/Y H:i')); ?> Â· <?php echo e($visitTimezone); ?></p></div>
        <a class="btn" href="<?php echo e(route('visits.index',[$currentPharmacy,'date'=>$visit->visit_date])); ?>">Kembali ke antrean</a>
    </div>
<?php echo $__env->make('partials.clinical-path', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php ($registeredServices = \App\Services\VisitRegistration::services($visit)); ?>
<?php if($registeredServices): ?>
<section class="card"><header class="clinical-section"><div class="help-heading"><h2>Layanan terdaftar</h2><?php if (isset($component)) { $__componentOriginal00b0512d0342aba0454c36c2dd4cabca = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal00b0512d0342aba0454c36c2dd4cabca = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.help-tooltip','data' => ['label' => 'Informasi Layanan terdaftar']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('help-tooltip'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Informasi Layanan terdaftar']); ?>Tarif tersimpan saat pendaftaran kunjungan. <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal00b0512d0342aba0454c36c2dd4cabca)): ?>
<?php $attributes = $__attributesOriginal00b0512d0342aba0454c36c2dd4cabca; ?>
<?php unset($__attributesOriginal00b0512d0342aba0454c36c2dd4cabca); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal00b0512d0342aba0454c36c2dd4cabca)): ?>
<?php $component = $__componentOriginal00b0512d0342aba0454c36c2dd4cabca; ?>
<?php unset($__componentOriginal00b0512d0342aba0454c36c2dd4cabca); ?>
<?php endif; ?></div></header><div class="table-wrap"><table><thead><tr><th>Layanan</th><th>Jumlah</th><th>Tarif</th><th>Total</th></tr></thead><tbody>
<?php $__currentLoopData = $registeredServices; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $service): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><tr><td><?php echo e($service['name']); ?></td><td><?php echo e($service['quantity']); ?></td><td>Rp <?php echo e(number_format($service['unit_price'],2,',','.')); ?></td><td>Rp <?php echo e(number_format($service['total'],2,',','.')); ?></td></tr><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
</tbody></table></div></section>
<?php endif; ?>
<?php if($visit->intake): ?>
<section class="card" style="margin-bottom:20px"><header class="clinical-section"><h2>Pemeriksaan awal saat pendaftaran</h2></header><dl class="clinical-details">
<?php $__currentLoopData = \App\Services\ExaminationRecords::VITALS; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key=>[$label,$unit]): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><div><dt><?php echo e($label); ?></dt><dd><?php echo e(isset($visit->intake[$key]) ? $visit->intake[$key].' '.$unit : 'Belum dicatat'); ?></dd></div><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
<div class="wide"><dt>Hasil tes urine</dt><dd><?php echo e($visit->intake['urine_test'] ?? 'Belum dicatat'); ?></dd></div>
</dl></section>
<?php endif; ?>
<?php if($access->allows('billing.view') && in_array($visit->status,['REGISTERED','IN_PROGRESS','COMPLETED'])): ?><div class="notice"><?php if($serviceBill): ?><a class="btn small" href="<?php echo e(route('billing.show',[$currentPharmacy,$serviceBill->id])); ?>">Tagihan layanan Â· <?php echo e($serviceBill->number); ?></a><?php endif; ?></div><?php endif; ?>
<?php if($access->allows('billing.view') || $access->allows('sales.view')): ?><section class="card visit-cost-summary"><header class="clinical-section"><div class="help-heading"><h2>Ringkasan biaya dan penyerahan</h2><?php if (isset($component)) { $__componentOriginal00b0512d0342aba0454c36c2dd4cabca = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal00b0512d0342aba0454c36c2dd4cabca = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.help-tooltip','data' => ['label' => 'Informasi Ringkasan biaya dan penyerahan']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('help-tooltip'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Informasi Ringkasan biaya dan penyerahan']); ?>Biaya layanan, obat resep, dan penjualan umum dibayar terpisah. Total transaksi gabungan dapat mencakup resep pasien lain. <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal00b0512d0342aba0454c36c2dd4cabca)): ?>
<?php $attributes = $__attributesOriginal00b0512d0342aba0454c36c2dd4cabca; ?>
<?php unset($__attributesOriginal00b0512d0342aba0454c36c2dd4cabca); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal00b0512d0342aba0454c36c2dd4cabca)): ?>
<?php $component = $__componentOriginal00b0512d0342aba0454c36c2dd4cabca; ?>
<?php unset($__componentOriginal00b0512d0342aba0454c36c2dd4cabca); ?>
<?php endif; ?></div></header><div class="visit-cost-summary-body"><?php if($access->allows('billing.view')): ?><p><strong>Layanan:</strong> <?php if($serviceBill): ?> <?php echo e($serviceBill->number); ?> ? <?php echo e($serviceBill->status === 'PAID' ? 'Lunas' : 'Menunggu pembayaran'); ?> ? Rp <?php echo e(number_format($serviceBill->total,2,',','.')); ?> <?php else: ?> Belum ada tagihan layanan. <?php endif; ?></p><?php endif; ?> <?php if($access->allows('sales.view')): ?><p><strong>Obat resep:</strong></p><?php $__empty_1 = true; $__currentLoopData = $prescriptionSales; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $sale): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><p><a href="<?php echo e(route('sales.show',[$currentPharmacy,$sale->id])); ?>"><?php echo e($sale->number); ?></a> ? <?php echo e($sale->status === 'PAID' ? 'Lunas' : 'Belum dibayar'); ?> ? <?php echo e($sale->handed_over_at ? 'Sudah diserahkan' : ($sale->status === 'PAID' ? 'Menunggu penyerahan' : 'Belum dapat diserahkan')); ?> ? Rp <?php echo e(number_format($sale->total,2,',','.')); ?></p><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><p>Belum ada transaksi obat resep.</p><?php endif; ?> <?php endif; ?></div></section><?php endif; ?>
    <div class="visit-detail-grid">
        <section class="card visit-patient-card" aria-labelledby="visit-detail-title">
            <header class="visit-detail-header"><div class="visit-queue-mark" aria-label="Nomor antrean <?php echo e($visit->queue_number); ?>"><span>ANTREAN</span><strong>#<?php echo e(str_pad($visit->queue_number,3,'0',STR_PAD_LEFT)); ?></strong></div><div class="visit-detail-heading"><h2 id="visit-detail-title">Informasi kunjungan <?php if (isset($component)) { $__componentOriginal00b0512d0342aba0454c36c2dd4cabca = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal00b0512d0342aba0454c36c2dd4cabca = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.help-tooltip','data' => ['label' => 'Informasi Informasi kunjungan']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('help-tooltip'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Informasi Informasi kunjungan']); ?>Identitas pasien dan kebutuhan layanan. <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal00b0512d0342aba0454c36c2dd4cabca)): ?>
<?php $attributes = $__attributesOriginal00b0512d0342aba0454c36c2dd4cabca; ?>
<?php unset($__attributesOriginal00b0512d0342aba0454c36c2dd4cabca); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal00b0512d0342aba0454c36c2dd4cabca)): ?>
<?php $component = $__componentOriginal00b0512d0342aba0454c36c2dd4cabca; ?>
<?php unset($__componentOriginal00b0512d0342aba0454c36c2dd4cabca); ?>
<?php endif; ?></h2></div><span class="badge <?php echo e($visit->status === 'CANCELLED' ? 'neutral' : ''); ?>"><?php echo e(\App\Services\VisitRegistration::STATUSES[$visit->status]); ?></span></header>
            <div class="visit-detail-body"><?php echo $__env->make("visits.clinical-status", array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                <div class="visit-patient-heading"><div><span class="visit-detail-label">Pasien</span><h3><?php echo e($visit->patient_name); ?></h3><p><?php echo e($visit->medical_record_number); ?></p></div><?php if($access->allows('patients.view')): ?><a class="btn small" href="<?php echo e(route('patients.show',[$currentPharmacy,$visit->patient_id])); ?>">Buka master pasien</a><?php endif; ?></div>
                <dl class="visit-identity-grid"><div><dt>Dokter penanggung jawab</dt><dd><?php echo e($visit->doctor_name); ?><small><?php echo e($visit->doctor_code); ?></small></dd></div><div><dt>Jenis kunjungan</dt><dd><?php echo e(\App\Services\VisitRegistration::TYPES[$visit->type]); ?></dd></div></dl>
                <section class="visit-complaint" aria-labelledby="visit-complaint-title"><h3 id="visit-complaint-title">Keluhan utama</h3><p class="visit-text"><?php echo e($visit->complaint); ?></p></section>
                <?php if($access->allows('patients.view_clinical')): ?><div class="visit-allergy-note <?php echo e($allergies ? 'has-allergies' : ''); ?>"><strong>Alergi obat</strong><p class="visit-text"><?php echo e($allergies ?: 'Belum dicatat. Konfirmasikan kepada pasien; ini bukan pernyataan tidak ada alergi.'); ?></p><small>Informasi dari master pasien saat ini.</small></div><?php endif; ?>
                <?php if($visit->status === 'CANCELLED'): ?><div class="visit-state-note cancelled"><strong>Kunjungan dibatalkan</strong><p class="visit-text"><?php echo e($visit->cancellation); ?></p><small>Nomor antrean tetap disimpan dan tidak digunakan ulang.</small></div>
                <?php elseif($visit->status === 'IN_PROGRESS'): ?><div class="visit-state-note"><strong>Layanan sedang berlangsung</strong><p>Dimulai <?php echo e(\Illuminate\Support\Carbon::parse($visit->started_at)->timezone($visitTimezone)->format('d/m/Y H:i')); ?>. Lanjutkan pencatatan SOAP melalui modul pemeriksaan.</p></div>
                <?php elseif($visit->status === 'COMPLETED' && $visit->completed_at): ?><div class="visit-state-note"><strong>Kunjungan selesai</strong><p>Layanan ditutup pada <?php echo e(\Illuminate\Support\Carbon::parse($visit->completed_at)->timezone($visitTimezone)->format('d/m/Y H:i')); ?>.</p></div><?php endif; ?>
            </div>
        </section>
        <aside class="visit-detail-actions" aria-label="Tindakan kunjungan"><?php echo $__env->make("visits.clinical-actions", array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            <?php if(in_array($visit->status,['IN_PROGRESS','COMPLETED']) && $canReadExamination): ?>
                <section class="card visit-action-card"><header><h2>Pemeriksaan / SOAP</h2><p><?php echo e($soapStatus === 'FINAL' ? 'Lihat hasil pemeriksaan dan riwayat versi yang sudah difinalkan.' : 'Lengkapi SOAP yang menyusul melalui formulir atau unggah gambar pemeriksaan.'); ?></p></header><div class="visit-action-body"><a class="btn primary" href="<?php echo e(route('examinations.show',[$currentPharmacy,$visit->id])); ?>">Buka pemeriksaan</a></div></section>
            <?php endif; ?>
            <?php if($visit->status === 'REGISTERED' && $currentPharmacy->status === 'ACTIVE' && $access->allows('visits.update')): ?>
                <section class="card visit-action-card"><header><h2>Lanjutkan layanan</h2><p>Pastikan pasien sudah hadir dan dokter siap sebelum memulai.</p></header><div class="visit-action-body">
                    <p>Tagihan layanan harus lunas agar kunjungan masuk ke daftar pemeriksaan.</p>
                    <a class="btn" href="<?php echo e(route('visits.edit',[$currentPharmacy,$visit->id])); ?>">Edit pendaftaran</a><p class="visit-action-hint">Data pendaftaran dikunci setelah layanan dimulai.</p>
                </div></section>
            <?php endif; ?>
            <?php if($visit->status === 'REGISTERED' && $access->allows('visits.cancel')): ?>
                <details class="card visit-cancel-card" <?php if($errors->has('reason')): ?> open <?php endif; ?>><summary><div><h2>Batalkan kunjungan</h2><p>Untuk pasien batal datang atau pendaftaran keliru.</p></div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
                    <form method="post" class="visit-cancel-body" action="<?php echo e(route('visits.transition',[$currentPharmacy,$visit->id])); ?>" data-confirm="Batalkan kunjungan ini? Pasien dikeluarkan dari antrean; nomor antrean tidak digunakan ulang." data-confirm-title="Batalkan kunjungan" data-confirm-label="Batalkan kunjungan" data-confirm-variant="danger"><?php echo csrf_field(); ?><input type="hidden" name="revision" value="<?php echo e($visit->revision); ?>"><input type="hidden" name="action" value="cancel"><label for="visit-cancel-reason">Alasan pembatalan *<textarea id="visit-cancel-reason" name="reason" required maxlength="1000" rows="3" placeholder="Tuliskan alasan pembatalan"><?php echo e(old('reason')); ?></textarea></label><?php $__errorArgs = ['reason'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p class="visit-field-error"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><p class="visit-action-hint">Data kunjungan tetap tersimpan. Nomor antrean tidak digunakan ulang.</p><button class="btn danger">Batalkan kunjungan</button></form>
                </details>
            <?php endif; ?>
        </aside>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /app/resources/views/visits/show.blade.php ENDPATH**/ ?>