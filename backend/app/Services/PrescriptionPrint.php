<?php

namespace App\Services;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class PrescriptionPrint
{
    public static function scanText(object $prescription, array $payload): string
    {
        $patient = $payload['patient'];
        $fields = [
            'Nomor Izin Dokter (SIP)' => $payload['doctor']['sip_number'] ?? null,
            'Nama Dokter' => $payload['doctor']['name'],
            'Nomor Resep' => $prescription->number,
            'Nama Pasien' => $patient['name'],
            'Tanggal Lahir' => $patient['birth_date'] ?? null,
            'Jenis Kelamin' => self::sex($patient['sex'] ?? null),
            'Nomor Rekam Medis' => $patient['medical_record_number'],
        ];
        $lines = ['IDENTITAS RESEP'];
        foreach ($fields as $label => $value) {
            $lines[] = $label.': '.($value ? preg_replace('/\s+/u', ' ', trim((string) $value)) : 'Belum tercatat');
        }

        return implode("\n", $lines);
    }

    public static function sex(?string $sex): string
    {
        return ['male' => 'Laki-laki', 'female' => 'Perempuan'][$sex ?? ''] ?? ($sex ?: 'Belum tercatat');
    }

    public static function qr(string $text): string
    {
        $options = new QROptions(['outputInterface' => QRMarkupSVG::class, 'outputBase64' => true,
            'eccLevel' => EccLevel::M, 'addQuietzone' => true, 'quietzoneSize' => 4,
            'drawLightModules' => true, 'svgUseFillAttributes' => true]);

        return (new QRCode($options))->render($text);
    }
}
