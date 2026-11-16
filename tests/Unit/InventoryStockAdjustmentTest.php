<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryStockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: OrderStatus, 1: bool}>
     */
    public static function consumesStockProvider(): array
    {
        return [
            'new does not consume stock' => [OrderStatus::New, false],
            'processing consumes stock' => [OrderStatus::Processing, true],
            'completed consumes stock' => [OrderStatus::Completed, true],
            'cancelled does not consume stock' => [OrderStatus::Cancelled, false],
        ];
    }

    #[DataProvider('consumesStockProvider')]
    public function test_order_status_consumes_stock(OrderStatus $status, bool $expected): void
    {
        $this->assertSame($expected, $status->consumesStock());
    }

    public function test_cancelling_an_order_restores_stock_of_every_product(): void
    {
        $first = Product::factory()->create(['stock_quantity' => 10]);
        $second = Product::factory()->create(['stock_quantity' => 20]);
        $order = Order::factory()->create(['status' => OrderStatus::New]);
        $order->items()->create(['product_id' => $first->id, 'qty' => 3, 'unit_price' => 1000]);
        $order->items()->create(['product_id' => $second->id, 'qty' => 5, 'unit_price' => 2000]);

        $order->status = OrderStatus::Processing;
        $order->save();

        $this->assertSame(7, $first->fresh()->stock_quantity);
        $this->assertSame(15, $second->fresh()->stock_quantity);

        $order->status = OrderStatus::Cancelled;
        $order->save();

        $this->assertSame(10, $first->fresh()->stock_quantity);
        $this->assertSame(20, $second->fresh()->stock_quantity);
    }

    public function test_item_changes_adjust_stock_for_each_changed_product(): void
    {
        $increased = Product::factory()->create(['stock_quantity' => 50]);
        $decreased = Product::factory()->create(['stock_quantity' => 50]);
        $unchanged = Product::factory()->create(['stock_quantity' => 50]);
        $order = Order::factory()->create(['status' => OrderStatus::Processing]);

        $oldItems = [
            ['product_id' => $increased->id, 'qty' => 1],
            ['product_id' => $decreased->id, 'qty' => 6],
            ['product_id' => $unchanged->id, 'qty' => 2],
        ];
        $newItems = [
            ['product_id' => $increased->id, 'qty' => 4],
            ['product_id' => $decreased->id, 'qty' => 2],
            ['product_id' => $unchanged->id, 'qty' => 2],
        ];

        InventoryService::adjustForOrderItemChanges($order, $newItems, $oldItems);

        $this->assertSame(47, $increased->fresh()->stock_quantity);
        $this->assertSame(54, $decreased->fresh()->stock_quantity);
        $this->assertSame(50, $unchanged->fresh()->stock_quantity);
    }

    public function test_item_changes_do_not_adjust_stock_for_new_orders(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $order = Order::factory()->create(['status' => OrderStatus::New]);

        InventoryService::adjustForOrderItemChanges(
            $order,
            [['product_id' => $product->id, 'qty' => 5]],
            [['product_id' => $product->id, 'qty' => 1]],
        );

        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    public function test_item_changes_load_products_with_a_single_query(): void
    {
        $products = Product::factory()->count(4)->create(['stock_quantity' => 50]);
        $order = Order::factory()->create(['status' => OrderStatus::Processing]);

        $oldItems = $products->map(fn (Product $product): array => ['product_id' => $product->id, 'qty' => 1])->all();
        $newItems = $products->map(fn (Product $product): array => ['product_id' => $product->id, 'qty' => 3])->all();

        DB::flushQueryLog();
        DB::enableQueryLog();

        InventoryService::adjustForOrderItemChanges($order, $newItems, $oldItems);

        $productSelects = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_starts_with($query['query'], 'select') && str_contains($query['query'], '"products"'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(1, $productSelects);
    }
}