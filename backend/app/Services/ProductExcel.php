<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\ProductIngredient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;

class ProductExcel
{
    public const COLUMNS = [
        'Obat' => ['SKU' => 'sku', 'Barcode' => 'barcode', 'Brand' => 'brand', 'Nama generik' => 'generic_name', 'Kategori' => 'category', 'Bentuk sediaan' => 'dosage_form', 'Satuan dasar' => 'base_unit', 'Satuan pembelian' => 'purchase_unit', 'Isi pembelian' => 'purchase_conversion', 'Harga beli' => 'purchase_price', 'Harga jual dasar' => 'selling_price', 'Stok minimum' => 'minimum_stock', 'Stok maksimum' => 'maximum_stock', 'Perlu resep (0/1)' => 'requires_prescription', 'Catatan' => 'notes'],
        'Kandungan' => ['SKU' => 'sku', 'Nama kandungan' => 'name', 'Kekuatan' => 'amount', 'Satuan kekuatan' => 'unit', 'Jumlah basis' => 'basis_quantity', 'Satuan basis' => 'basis_unit'],
        'Satuan jual' => ['SKU' => 'sku', 'Satuan' => 'unit', 'Isi satuan dasar' => 'conversion', 'Harga jual' => 'price'],
    ];

    public function template(?Pharmacy $pharmacy = null): string
    {
        $book = new SimpleXLSXGen;
        $examples = [
            'Obat' => [
                ['CONTOH-PCM-500', "\0".'0012345678901', 'Brand Contoh A', 'Paracetamol', 'Obat', 'Tablet', 'Tablet', 'Box', 100, 50000, 1000, 10, 200, 0, 'Data contoh; ganti atau hapus sebelum impor. Harga simulasi.'],
                ['CONTOH-VITC-500', "\0".'0012345678902', 'Brand Contoh B', 'Vitamin C', 'Vitamin & suplemen', 'Tablet', 'Tablet', 'Box', 100, 80000, 1500, 20, 300, 0, 'Data contoh; ganti atau hapus sebelum impor. Harga simulasi.'],
            ],
            'Kandungan' => [
                ['CONTOH-PCM-500', 'Paracetamol', 500, 'mg', 1, 'Tablet'],
                ['CONTOH-VITC-500', 'Vitamin C', 500, 'mg', 1, 'Tablet'],
            ],
            'Satuan jual' => [
                ['CONTOH-PCM-500', 'Strip', 10, 9000],
                ['CONTOH-VITC-500', 'Strip', 10, 14000],
            ],
        ];
        foreach (self::COLUMNS as $name => $columns) {
            $header = array_map(fn ($label) => '<style bgcolor="#18765B" color="#FFFFFF"><b>'.$label.'</b></style>', array_keys($columns));
            $blank = array_fill(0, count($columns), '');
            $blank[0] = '<style nf="@"></style>';
            if ($name === 'Obat') {
                $blank[1] = '<style nf="@"></style>';
            }
            $book->addSheet([$header, ...$examples[$name], ...array_fill(0, 500 - count($examples[$name]), $blank)], $name)->freezePanes('B2');
            foreach (array_keys($columns) as $index => $label) {
                $book->setColWidth($index + 1, max(18, min(32, strlen($label) + 4)));
            }
        }
        $guide = [
            ['<b>Panduan impor obat</b>', '<b>Ketentuan</b>'],
            ['Mulai', 'Isi sheet Obat mulai baris 2. Jangan ubah nama sheet atau judul kolom. Baris kosong diabaikan.'],
            ['Dua obat contoh', 'Baris 2 dan 3 berisi Paracetamol dan Vitamin C, beserta contoh Kandungan dan Satuan jual. Semua harga hanya simulasi. Ganti atau hapus contoh pada ketiga sheet sebelum impor; contoh yang dibiarkan akan ikut diimpor.'],
            ['Batas', 'XLSX maksimal 5 MB, 500 produk. Hanya menambah produk baru, tidak mengubah produk yang sudah ada.'],
            ['Kolom wajib Obat', 'SKU, Brand, Kategori, Satuan dasar, Satuan pembelian, Isi pembelian, Harga jual dasar, Stok minimum, Perlu resep (0/1).'],
            ['SKU dan barcode', 'Gunakan format Text agar nol di depan tidak hilang. SKU dan barcode harus unik di apotek ini.'],
            ['Angka', 'Gunakan angka tanpa Rp atau pemisah ribuan. Desimal menggunakan titik, contoh 1250.50. Harga maksimal dua angka desimal.'],
            ['Stok', 'Stok minimum/maksimum adalah batas pengingat, bukan saldo awal. Impor tidak menambah stok. Produk baru selalu aktif.'],
            ['Konversi', 'Isi pembelian terhadap satuan dasar. Jika satuannya sama, isi 1. Contoh Box berisi 100 Tablet: isi 100.'],
            ['Perlu resep', 'Isi 1 jika memerlukan resep, 0 jika tidak.'],
            ['Kandungan (opsional)', 'Satu baris per kandungan. SKU harus ada di sheet Obat. Kekuatan, satuan kekuatan, jumlah basis, satuan basis harus diisi bersama atau dikosongkan bersama.'],
            ['Satuan jual (opsional)', 'Satu baris per satuan tambahan. SKU harus ada di sheet Obat. Satuan dasar memakai Harga jual dasar pada sheet Obat.'],
            ['Harga kemasan', 'Contoh Satuan jual: SKU CONTOH-PCM-500, Strip, isi 10, harga 9000. Maksimal 20 satuan tambahan, 20 kandungan per produk.'],
            ['Validasi', 'Jika ada kesalahan, seluruh impor dibatalkan. Perbaiki baris yang dilaporkan lalu unggah kembali. Rumus Excel tidak didukung; gunakan nilai tetap.'],
            ['Kategori', implode(', ', $pharmacy ? DB::table('product_categories')->where('pharmacy_id', $pharmacy->id)->where('active', true)->orderBy('name')->pluck('name')->all() : Product::CATEGORIES)],
            ['Satuan', implode(', ', Product::UNITS)],
            ['Bentuk sediaan', implode(', ', Product::FORMS)],
            ['Satuan kekuatan', implode(', ', ProductIngredient::STRENGTH_UNITS)],
        ];
        $book->addSheet($guide, 'Panduan')->setColWidth(1, 32)->setColWidth(2, 110)->freezePanes('A2');

        return (string) $book;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }

    private function checkArchive(string $bytes): void
    {
        $end = strrpos($bytes, "PK\x05\x06");
        if ($end === false || strlen($bytes) < $end + 22) {
            $this->fail('File bukan XLSX yang valid. Gunakan template unduhan.');
        }
        $directory = unpack('ventries/Vsize/Voffset', substr($bytes, $end + 10, 10));
        if ($directory['entries'] > 1000 || $end < $directory['offset'] + $directory['size']) {
            $this->fail('Struktur file Excel tidak didukung.');
        }
        $offset = $directory['offset'];
        $total = 0;
        for ($entry = 0; $entry < $directory['entries']; $entry++) {
            if (substr($bytes, $offset, 4) !== "PK\x01\x02" || $offset + 46 > $end) {
                $this->fail('Struktur file Excel rusak.');
            }
            $size = unpack('V', substr($bytes, $offset + 24, 4))[1];
            $compressed = unpack('V', substr($bytes, $offset + 20, 4))[1];
            $method = unpack('v', substr($bytes, $offset + 10, 2))[1];
            $local = unpack('V', substr($bytes, $offset + 42, 4))[1];
            $lengths = unpack('vname/vextra/vcomment', substr($bytes, $offset + 28, 6));
            $total += $size;
            if ($total > 20 * 1024 * 1024) {
                $this->fail('Isi file Excel terlalu besar setelah dibuka (maksimal 20 MB).');
            }
            if (substr($bytes, $local, 4) !== "PK\x03\x04" || $local + 30 > $directory['offset'] || ! in_array($method, [0, 8], true)) {
                $this->fail('Struktur kompresi file Excel tidak didukung.');
            }
            $localLengths = unpack('vname/vextra', substr($bytes, $local + 26, 4));
            $dataOffset = $local + 30 + array_sum($localLengths);
            if ($dataOffset + $compressed > $directory['offset']) {
                $this->fail('File Excel terpotong atau rusak.');
            }
            $payload = substr($bytes, $dataOffset, $compressed);
            $decoded = $method === 8 ? @gzinflate($payload, $size + 1) : $payload;
            if ($decoded === false || strlen($decoded) !== $size) {
                $this->fail('Ukuran isi file Excel tidak valid.');
            }
            $offset += 46 + array_sum($lengths);
        }
    }

    private function rows(SimpleXLSX $book, string $name, int $limit): array
    {
        $index = array_search($name, $book->sheetNames(), true);
        if ($index === false) {
            if ($name === 'Obat') {
                $this->fail('Sheet Obat tidak ditemukan. Gunakan template unduhan.');
            }

            return [];
        }
        [$width, $height] = $book->dimension($index);
        if ($width > count(self::COLUMNS[$name]) || $height > $limit + 1) {
            $this->fail("Sheet $name melebihi batas kolom atau $limit baris. Hapus baris/kolom tambahan.");
        }
        $rows = [];
        foreach ($book->readRowsEx($index, $limit + 1) as $index => $cells) {
            $values = [];
            foreach ($cells as $cell) {
                if (isset($cell['f']) && $cell['f'] !== '') {
                    $this->fail("Sheet $name baris ".($index + 1).': rumus tidak didukung. Gunakan nilai tetap.');
                }
                $value = $cell['value'] ?? '';
                $values[] = trim((string) $value);
            }
            $values = array_pad($values, count(self::COLUMNS[$name]), '');
            if ($index === 0) {
                if ($values !== array_keys(self::COLUMNS[$name])) {
                    $this->fail("Judul kolom sheet $name tidak sesuai template.");
                }

                continue;
            }
            if (! array_filter($values, fn ($value) => $value !== '')) {
                continue;
            }
            $rows[$index + 1] = array_combine(array_values(self::COLUMNS[$name]), array_map(fn ($value) => $value === '' ? null : $value, $values));
        }

        return $rows;
    }

    public function import(Pharmacy $pharmacy, int $actor, UploadedFile $file): int
    {
        $bytes = file_get_contents($file->getRealPath());
        $this->checkArchive($bytes);
        $book = SimpleXLSX::parseData($bytes);
        if (! $book) {
            $this->fail('File Excel tidak dapat dibaca. Simpan ulang sebagai XLSX tanpa password.');
        }
        $rows = $this->rows($book, 'Obat', 500);
        if (! $rows) {
            $this->fail('Sheet Obat belum berisi data. Isi mulai baris 2.');
        }
        $related = ['ingredients' => $this->rows($book, 'Kandungan', 2000), 'selling_units' => $this->rows($book, 'Satuan jual', 2000)];

        try {
            return app(AccessManager::class)->locked($pharmacy, function () use ($pharmacy, $actor, $rows, $related) {
                $access = app(AccessManager::class);
                $access->actor($pharmacy, $actor, 'drugs.create');
                $skus = [];
                $barcodes = [];
                $prepared = [];
                $errors = [];
                foreach ($rows as $number => $row) {
                    $sku = mb_strtoupper(trim((string) $row['sku']));
                    if (isset($skus[$sku])) {
                        $errors['import.'.$number] = "Obat baris $number: SKU $sku berulang dalam file.";
                    }
                    $skus[$sku] = true;
                    if ($row['barcode'] !== null && isset($barcodes[$row['barcode']])) {
                        $errors['import.'.$number] = "Obat baris $number: barcode berulang dalam file.";
                    }
                    if ($row['barcode'] !== null) {
                        $barcodes[$row['barcode']] = true;
                    }
                    foreach ($related as $field => $details) {
                        $row[$field] = [];
                        foreach ($details as $detail) {
                            if (mb_strtoupper(trim((string) $detail['sku'])) === $sku) {
                                unset($detail['sku']);
                                $row[$field][] = $detail;
                            }
                        }
                    }
                    try {
                        $prepared[$number] = ProductInput::validate(Request::create('/', 'POST', $row), $pharmacy);
                    } catch (ValidationException $exception) {
                        $errors['import.'.$number] = "Obat baris $number ($sku): ".implode(' ', $exception->validator->errors()->all());
                    }
                }
                foreach ($related as $field => $details) {
                    foreach ($details as $number => $detail) {
                        if (! isset($skus[mb_strtoupper(trim((string) $detail['sku']))])) {
                            $errors[$field.'.'.$number] = ($field === 'ingredients' ? 'Kandungan' : 'Satuan jual')." baris $number: SKU tidak ada di sheet Obat.";
                        }
                    }
                }
                if ($errors) {
                    throw ValidationException::withMessages(array_slice($errors, 0, 50, true));
                }
                foreach ($prepared as $data) {
                    $ingredients = $data['ingredients'] ?? [];
                    unset($data['ingredients'], $data['name']);
                    $product = $pharmacy->products()->create($data + ['name' => $data['brand'], 'active' => true]);
                    foreach ($ingredients as $index => $ingredient) {
                        $product->ingredients()->create($ingredient + ['position' => $index]);
                    }
                    $access->audit($pharmacy, $actor, 'product.created', $product->id, ['source' => 'excel']);
                }
                $access->audit($pharmacy, $actor, 'products.imported', 'products', ['count' => count($prepared)]);

                return count($prepared);
            });
        } catch (UniqueConstraintViolationException) {
            $this->fail('SKU atau barcode sudah digunakan. Tidak ada data yang diimpor; periksa file lalu coba lagi.');
        }
    }
}
