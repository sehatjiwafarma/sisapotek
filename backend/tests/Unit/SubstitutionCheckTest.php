<?php
namespace Tests\Unit;
use App\Models\Product;
use App\Models\ProductIngredient;
use App\Services\SubstitutionCheck;
use PHPUnit\Framework\TestCase;
class SubstitutionCheckTest extends TestCase
{
    private function product(string $name, string $strength, string $unit = 'mg', ?string $form = 'Tablet'): Product
    {
        \Illuminate\Database\Eloquent\Model::unguard();
        $product = new Product();
        $product->setRawAttributes(['base_unit' => 'Tablet', 'dosage_form' => $form]);
        $product->setRelation('ingredients', collect([new ProductIngredient(['name' => $name, 'amount' => $strength, 'unit' => $unit, 'basis_quantity' => 1, 'basis_unit' => 'Tablet'])]));
        return $product;
    }
    public function test_conversion_and_incompatible_products(): void
    {
        $original = $this->product('Alprazolam', '0.5');
        $result = SubstitutionCheck::assess($original, $this->product('alprazolam', '1000', 'mcg'));
        $this->assertTrue($result['ok']);
        $this->assertEquals(12, 6 * $result['ratio']);
        $this->assertFalse(SubstitutionCheck::assess($original, $this->product('Clonazepam', '1'))['ok']);
        $this->assertFalse(SubstitutionCheck::assess($original, $this->product('Alprazolam', '1', 'mg', 'Kapsul'))['ok']);
        $this->assertFalse(SubstitutionCheck::assess($original, $this->product('Alprazolam', '1', 'mg', null))['ok']);
        $combination = $this->product('Alprazolam', '1');
        $combination->ingredients->push(new ProductIngredient(['name' => 'Lainnya']));
        $this->assertFalse(SubstitutionCheck::assess($original, $combination)['ok']);
    }
}
