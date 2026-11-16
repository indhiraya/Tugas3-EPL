<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductVisibleScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_visible_scope_returns_only_visible_products_of_visible_categories(): void
    {
        $visibleCategory = Category::factory()->create(['is_visible' => true]);
        $hiddenCategory = Category::factory()->create(['is_visible' => false]);

        $shown = Product::factory()->create(['category_id' => $visibleCategory->id, 'is_visible' => true]);
        $hiddenProduct = Product::factory()->create(['category_id' => $visibleCategory->id, 'is_visible' => false]);
        $inHiddenCategory = Product::factory()->create(['category_id' => $hiddenCategory->id, 'is_visible' => true]);
        $withoutCategory = Product::factory()->create(['category_id' => null, 'is_visible' => true]);

        $ids = Product::query()->visible()->pluck('id')->all();

        $this->assertSame([$shown->id], $ids);
        $this->assertNotContains($hiddenProduct->id, $ids);
        $this->assertNotContains($inHiddenCategory->id, $ids);
        $this->assertNotContains($withoutCategory->id, $ids);
    }
}