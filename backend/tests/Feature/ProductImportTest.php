<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\ProductExcel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Impor']);
        $this->actingAs($owner);

        return [$owner, $pharmacy];
    }

    private function row(string $sku = 'PCM-IMPORT'): array
    {
        return [$sku, "\0".'0012345678901', 'Brand Impor', 'Paracetamol', 'Obat', 'Tablet', 'Tablet', 'Box', 100, 50000, 1000, 10, 200, 0, 'Data impor'];
    }

    private function file(array $rows, array $ingredients = [], array $units = []): UploadedFile
    {
        $book = SimpleXLSXGen::fromArray([array_keys(ProductExcel::COLUMNS['Obat']), ...$rows], 'Obat');
        $book->addSheet([array_keys(ProductExcel::COLUMNS['Kandungan']), ...$ingredients], 'Kandungan');
        $book->addSheet([array_keys(ProductExcel::COLUMNS['Satuan jual']), ...$units], 'Satuan jual');

        return UploadedFile::fake()->createWithContent('obat.xlsx', (string) $book);
    }

    public function test_template_download_has_two_valid_example_products_with_related_data(): void
    {
        [, $pharmacy] = $this->workspace();
        $this->get(route('products.import', $pharmacy))->assertOk()->assertSee('Unduh template Excel');
        $response = $this->get(route('products.import.template', $pharmacy))->assertOk();
        $book = SimpleXLSX::parseData($response->getContent());
        $this->assertSame(['Obat', 'Kandungan', 'Satuan jual', 'Panduan'], $book->sheetNames());
        $this->assertSame(array_keys(ProductExcel::COLUMNS['Obat']), $book->rows(0, 1)[0]);
        foreach ([0, 1, 2] as $sheet) {
            $rows = array_filter(array_slice($book->rows($sheet), 1), fn ($row) => trim((string) $row[0]) !== '');
            $this->assertCount(2, $rows);
            $this->assertSame(['CONTOH-PCM-500', 'CONTOH-VITC-500'], array_values(array_column($rows, 0)));
        }
        $this->assertSame('0012345678901', $book->rows(0, 3)[1][1]);
        $this->post(route('products.import.store', $pharmacy), ['file' => UploadedFile::fake()->createWithContent('contoh.xlsx', $response->getContent())])->assertSessionHasNoErrors()->assertRedirect(route('products.index', $pharmacy));
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('product_ingredients', 2);
        foreach ($pharmacy->products()->get() as $product) {
            $this->assertCount(1, $product->selling_units);
            $this->assertSame('Strip', $product->selling_units[0]['unit']);
        }
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_import_saves_products_ingredients_unit_prices_and_audit_without_stock(): void
    {
        [, $pharmacy] = $this->workspace();
        $file = $this->file([$this->row()], [['PCM-IMPORT', 'Paracetamol', 500, 'mg', 1, 'Tablet']], [['PCM-IMPORT', 'Strip', 10, 9000]]);
        $this->post(route('products.import.store', $pharmacy), ['file' => $file])->assertSessionHasNoErrors()->assertRedirect(route('products.index', $pharmacy));
        $product = $pharmacy->products()->firstOrFail();
        $this->assertSame('0012345678901', $product->barcode);
        $this->assertTrue($product->active);
        $this->assertSame('Paracetamol', $product->ingredients->first()->name);
        $this->assertEquals(9000, $product->selling_units[0]['price']);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('audit_entries', ['action' => 'products.imported', 'pharmacy_id' => $pharmacy->id]);
    }

    public function test_invalid_or_duplicate_rows_rollback_entire_import(): void
    {
        [, $pharmacy] = $this->workspace();
        $invalid = $this->row('BAD');
        $invalid[1] = '22222';
        $invalid[10] = -1;
        $this->post(route('products.import.store', $pharmacy), ['file' => $this->file([$this->row(), $invalid])])->assertSessionHasErrors('import.3');
        $this->assertDatabaseCount('products', 0);
        $this->post(route('products.import.store', $pharmacy), ['file' => $this->file([$this->row(), $this->row()])])->assertSessionHasErrors('import.3');
        $this->assertDatabaseCount('products', 0);
        $this->post(route('products.import.store', $pharmacy), ['file' => $this->file([$this->row()])])->assertSessionHasNoErrors();
        $this->post(route('products.import.store', $pharmacy), ['file' => $this->file([$this->row()])])->assertSessionHasErrors('import.2');
        $this->assertDatabaseCount('products', 1);
    }

    public function test_formulas_or_unknown_related_skus_are_rejected(): void
    {
        [, $pharmacy] = $this->workspace();
        $row = $this->row();
        $row[10] = '<f v="1000">500+500</f>';
        $this->post(route('products.import.store', $pharmacy), ['file' => $this->file([$row])])->assertSessionHasErrors('file');
        $this->post(route('products.import.store', $pharmacy), ['file' => $this->file([$this->row()], [], [['OTHER', 'Strip', 10, 9000]])])->assertSessionHasErrors('selling_units.2');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_wrong_headers_excess_rows_and_non_excel_uploads_are_rejected(): void
    {
        [, $pharmacy] = $this->workspace();
        $wrong = (string) SimpleXLSXGen::fromArray([['Kolom salah'], ['Contoh']], 'Obat');
        $this->post(route('products.import.store', $pharmacy), ['file' => $this->file([])])->assertSessionHasErrors('file');
        $this->post(route('products.import.store', $pharmacy), ['file' => UploadedFile::fake()->createWithContent('wrong.xlsx', $wrong)])->assertSessionHasErrors('file');
        $this->post(route('products.import.store', $pharmacy), ['file' => $this->file(array_fill(0, 501, $this->row()))])->assertSessionHasErrors('file');
        $this->post(route('products.import.store', $pharmacy), ['file' => UploadedFile::fake()->createWithContent('wrong.csv', 'sku,brand')])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_import_requires_create_permission_and_is_scoped_to_pharmacy(): void
    {
        [$owner, $pharmacy] = $this->workspace();
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Kedua']);
        $other->products()->create(['sku' => 'PCM-IMPORT', 'name' => 'Lain', 'brand' => 'Lain', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => 500, 'minimum_stock' => 0, 'active' => true]);
        $this->post(route('products.import.store', $pharmacy), ['file' => $this->file([$this->row()])])->assertSessionHasNoErrors();
        $this->assertSame(1, $pharmacy->products()->count());
        $reader = User::factory()->create();
        $membership = $pharmacy->memberships()->create(['user_id' => $reader->id, 'active' => true]);
        $role = $pharmacy->roles()->create(['name' => 'Pembaca', 'permissions' => ['drugs.view']]);
        app(AccessManager::class)->syncRoles($membership, collect([$role]));
        $this->actingAs($reader)->get(route('products.import.template', $pharmacy))->assertForbidden();
        $this->post(route('products.import.store', $pharmacy), ['file' => $this->file([$this->row('DENIED')])])->assertForbidden();
    }
}
