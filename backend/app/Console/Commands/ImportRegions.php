<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportRegions extends Command
{
    protected $signature = 'apotek:import-regions {file : CSV dengan header code,name}';

    protected $description = 'Impor master wilayah lokal secara transaksional; tidak menghapus kode yang sudah digunakan.';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! is_file($file) || ! ($handle = fopen($file, 'r'))) {
            $this->error('CSV tidak ditemukan.');

            return self::FAILURE;
        }
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (! $header || ! in_array('code', $header) || ! in_array('name', $header)) {
                throw new \RuntimeException('Header CSV harus code,name.');
            }
            $rows = [];
            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if (count($values) !== count($header)) {
                    throw new \RuntimeException('Baris CSV tidak valid.');
                }
                $row = array_combine($header, $values);
                $code = trim($row['code']);
                $name = trim($row['name']);
                if (! preg_match('/^\d{2}(?:\.\d{2}){0,2}(?:\.\d{4})?$/', $code) || ! $name || mb_strlen($name) > 150) {
                    throw new \RuntimeException('Kode/nama wilayah tidak valid: '.$code);
                }
                $parts = explode('.', $code);
                $level = count($parts);
                $parent = $level > 1 ? implode('.', array_slice($parts, 0, -1)) : null;
                $rows[$code] = ['code' => $code, 'name' => $name, 'parent_code' => $parent, 'level' => $level];
            }
            uasort($rows, fn ($a, $b) => $a['level'] <=> $b['level']);
            DB::transaction(function () use ($rows) {
                foreach ($rows as $row) {
                    if ($row['parent_code'] && ! isset($rows[$row['parent_code']]) && ! DB::table('regions')->where('code', $row['parent_code'])->exists()) {
                        throw new \RuntimeException('Wilayah induk tidak ditemukan.');
                    }
                }
                foreach (range(1, 4) as $level) {
                    foreach (array_chunk(array_values(array_filter($rows, fn ($row) => $row['level'] === $level)), 500) as $chunk) {
                        DB::table('regions')->upsert($chunk, ['code'], ['name', 'parent_code', 'level']);
                    }
                }
            });
            $this->info(count($rows).' wilayah diimpor.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            fclose($handle);
        }
    }
}
