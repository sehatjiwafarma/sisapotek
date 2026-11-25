<?php

namespace App\Services;

use App\Models\Pharmacy;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExaminationImages
{
    private array $paths = [];

    public function store(Pharmacy $pharmacy, int $actor, string $visit, array $data, array $files): array
    {
        Validator::make(['images' => $files], ['images' => 'array|max:6', 'images.*' => 'required|file|image|mimes:jpg,jpeg,png|max:5120|dimensions:max_width=6000,max_height=6000'])->validate();
        $ids = array_values(array_unique($data['image_ids'] ?? []));
        $query = DB::table('examination_images')->where('pharmacy_id', $pharmacy->id)->where('visit_id', $visit);
        if (count($ids) + count($files) > 10 || (clone $query)->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['image_ids' => 'Pilih maksimal 10 gambar milik pemeriksaan ini.']);
        }
        if ((clone $query)->count() + count($files) > 100) {
            throw ValidationException::withMessages(['images' => 'Batas arsip 100 gambar untuk kunjungan ini telah tercapai.']);
        }
        foreach ($files as $file) {
            $id = (string) Str::uuid();
            $path = 'clinical-images/'.$pharmacy->id.'/'.$id.'.enc';
            $bytes = $file->getContent();
            $this->paths[] = $path;
            if (! Storage::disk('local')->put($path, Crypt::encryptString($bytes))) {
                throw new \RuntimeException('Gambar belum dapat disimpan. Coba kembali.');
            }
            DB::table('examination_images')->insert(['id' => $id, 'pharmacy_id' => $pharmacy->id, 'visit_id' => $visit, 'path' => $path,
                'mime' => $file->getMimeType(), 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'uploaded_by' => $actor, 'created_at' => now()]);
            $ids[] = $id;
        }
        $data['image_ids'] = $ids;

        return $data;
    }

    public function cleanup(): void
    {
        foreach ($this->paths as $path) {
            if (! DB::table('examination_images')->where('path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    public static function assertAvailable(Pharmacy $pharmacy, string $visit, array $data): void
    {
        $ids = $data['image_ids'] ?? [];
        $files = DB::table('examination_images')->where('pharmacy_id', $pharmacy->id)->where('visit_id', $visit)->whereIn('id', $ids)->get();
        if ($files->count() !== count($ids)) {
            throw ValidationException::withMessages(['images' => 'Lampiran gambar tidak sesuai dengan kunjungan ini.']);
        }
        foreach ($files as $file) {
            if (! Storage::disk('local')->exists($file->path)) {
                throw ValidationException::withMessages(['images' => 'Lampiran gambar tidak tersedia. Unggah ulang sebelum finalisasi.']);
            }
        }
    }
}
