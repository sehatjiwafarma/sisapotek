<?php

namespace App\Console\Commands;

use App\Models\Pharmacy;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SeedDemoClinical extends Command
{
    protected $signature = 'apotek:demo-clinical {pharmacy : Kode atau UUID apotek tujuan}';

    protected $description = 'Tambahkan 10 pasien dan 4 dokter fiktif tanpa menimpa data atau membuat akun login.';

    public function handle(AccessManager $access, ClinicalMaster $master): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Data simulasi hanya dapat ditambahkan di lingkungan local atau testing.');

            return self::FAILURE;
        }
        $target = $this->argument('pharmacy');
        $pharmacy = Pharmacy::where('code', $target)->first();
        if (! $pharmacy && Str::isUuid($target)) {
            $pharmacy = Pharmacy::find($target);
        }
        if (! $pharmacy) {
            $this->error('Apotek tujuan tidak ditemukan.');

            return self::FAILURE;
        }
        $patients = [
            ['Andi Pratama', '1990-04-15', 'male', null],
            ['Siti Lestari', '1988-09-22', 'female', 'Simulasi: alergi obat pernah dilaporkan; perlu konfirmasi ulang.'],
            ['Budi Santoso', '1975-01-10', 'male', null],
            ['Dewi Anggraini', '1998-12-03', 'female', null],
            ['Rizky Ramadhan', '2015-06-18', 'male', null],
            ['Nabila Putri', '2018-03-25', 'female', null],
            ['Hasan Basri', '1956-08-12', 'male', 'Simulasi: riwayat reaksi obat belum teridentifikasi.'],
            ['Ratna Wulandari', '1963-11-05', 'female', null],
            ['Fajar Nugroho', '2002-07-30', 'male', null],
            ['Maya Puspita', '1994-02-14', 'female', null],
        ];
        $doctors = [
            ['dr. Aditya Pranata', 'Dokter umum'],
            ['dr. Anisa Maharani', 'Dokter umum'],
            ['dr. Raka Wijaya, Sp.A', 'Spesialis anak'],
            ['dr. Laras Permata, Sp.PD', 'Spesialis penyakit dalam'],
        ];
        $counts = $access->locked($pharmacy, function () use ($pharmacy, $patients, $doctors, $access, $master) {
            $owner = $pharmacy->memberships()->where('active', true)->whereHas('roles', fn ($query) => $query->where('is_owner', true))->first();
            if (! $owner) {
                throw new \RuntimeException('Diperlukan Owner aktif untuk mengisi master simulasi.');
            }
            $created = [0, 0];
            foreach ($patients as $index => [$name, $birth, $sex, $allergies]) {
                $key = 'd00d0001-0000-4000-8000-'.str_pad((string) ($index + 1), 12, '0', STR_PAD_LEFT);
                if (ClinicalMaster::patients($pharmacy)->where('request_key', $key)->exists()) {
                    continue;
                }
                $master->savePatient($pharmacy, $owner->user_id, [
                    'request_key' => $key, 'name' => $name.' (Dummy)', 'birth_date' => $birth, 'sex' => $sex,
                    'phone' => null, 'address' => 'ALAMAT DUMMY - Jalan Simulasi No. '.($index + 1).'. Bukan alamat operasional.',
                    'allergies' => $allergies ? 'DATA DUMMY - '.$allergies : null,
                    'notes' => 'DATA DUMMY untuk pengujian aplikasi. Identitas dan informasi klinis sepenuhnya fiktif.',
                ]);
                $created[0]++;
            }
            foreach ($doctors as $index => [$name, $specialty]) {
                $key = 'd00d0002-0000-4000-8000-'.str_pad((string) ($index + 1), 12, '0', STR_PAD_LEFT);
                if (ClinicalMaster::doctors($pharmacy)->where('request_key', $key)->exists()) {
                    continue;
                }
                $master->saveDoctor($pharmacy, $owner->user_id, [
                    'request_key' => $key, 'name' => $name.' (Dummy)', 'specialty' => $specialty,
                    'sip_number' => 'DUMMY-SIP-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    'str_number' => 'DUMMY-STR-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT), 'phone' => null,
                ]);
                $created[1]++;
            }
            if (array_sum($created)) {
                $access->audit($pharmacy, null, 'clinical.demo_seeded', 'clinical_masters', [
                    'demo' => true, 'patients_created' => $created[0], 'doctors_created' => $created[1],
                    'actor_context' => 'Perintah simulasi lokal; layanan master menggunakan Owner aktif sebagai konteks validasi.',
                ]);
            }

            return $created;
        });
        $this->info($counts[0].' pasien dan '.$counts[1].' dokter dummy ditambahkan ke '.$pharmacy->name.' ('.$pharmacy->code.').');
        $this->info('Data yang sudah ada dipertahankan. Akun login, tautan dokter, tanda tangan, dan kunjungan tidak dibuat.');

        return self::SUCCESS;
    }
}
