<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OrderFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $permissions = ['view_any_order', 'view_order', 'create_order', 'update_order'];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        $this->actingAs($user);
    }

    private function visibleProduct(int $stock = 10): Product
    {
        $category = Category::factory()->create(['is_visible' => true]);

        return Product::factory()->create([
            'category_id' => $category->id,
            'is_visible' => true,
            'stock_quantity' => $stock,
        ]);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $items
     * @return array<int|string, array<string, mixed>>
     */
    private function itemsByProduct(array $items): array
    {
        return collect($items)->keyBy('product_id')->all();
    }

    public function test_create_form_lists_only_visible_products_of_visible_categories(): void
    {
        $shown = $this->visibleProduct();

        $hiddenProduct = Product::factory()->create([
            'category_id' => $shown->category_id,
            'is_visible' => false,
        ]);

        $hiddenCategory = Category::factory()->create(['is_visible' => false]);
        $inHiddenCategory = Product::factory()->create([
            'category_id' => $hiddenCategory->id,
            'is_visible' => true,
        ]);

        $items = $this->itemsByProduct(
            Livewire::test(CreateOrder::class)->get('data.items')
        );

        $this->assertArrayHasKey($shown->id, $items);
        $this->assertArrayNotHasKey($hiddenProduct->id, $items);
        $this->assertArrayNotHasKey($inHiddenCategory->id, $items);
        $this->assertSame(0, $items[$shown->id]['qty']);
    }

    public function test_create_order_saves_only_items_with_quantity_above_zero(): void
    {
        $ordered = $this->visibleProduct();
        $skipped = $this->visibleProduct();
        $customer = Customer::factory()->create();

        $page = Livewire::test(CreateOrder::class)
            ->fillForm(['customer_id' => $customer->id, 'notes' => 'characterization']);

        $page->goToNextWizardStep();

        foreach ($page->get('data.items') as $key => $item) {
            if ($item['product_id'] === $ordered->id) {
                $page->set("data.items.{$key}.qty", 2);
            }
        }

        $page->call('create')->assertHasNoFormErrors();

        $order = Order::query()->where('notes', 'characterization')->firstOrFail();

        $this->assertMatchesRegularExpression('/^OR-\d{6}$/', $order->number);
        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertSame([$ordered->id], $order->items()->pluck('product_id')->all());
        $this->assertSame(2, $order->items()->first()->qty);
        $this->assertNotContains($skipped->id, $order->items()->pluck('product_id')->all());
    }

    public function test_create_order_requires_at_least_one_item_with_quantity(): void
    {
        $this->visibleProduct();
        $customer = Customer::factory()->create();

        $page = Livewire::test(CreateOrder::class)
            ->fillForm(['customer_id' => $customer->id, 'notes' => 'no-quantity']);

        $page->goToNextWizardStep()
            ->call('create')
            ->assertHasFormErrors(['items']);

        $this->assertDatabaseMissing('orders', ['notes' => 'no-quantity']);
    }

    public function test_create_order_rejects_quantity_above_available_stock(): void
    {
        $product = $this->visibleProduct(stock: 3);
        $customer = Customer::factory()->create();

        $page = Livewire::test(CreateOrder::class)
            ->fillForm(['customer_id' => $customer->id, 'notes' => 'too-many']);

        $page->goToNextWizardStep();

        foreach ($page->get('data.items') as $key => $item) {
            if ($item['product_id'] === $product->id) {
                $page->set("data.items.{$key}.qty", 5);
            }
        }

        $page->call('create')->assertHasFormErrors(['items']);

        $this->assertDatabaseMissing('orders', ['notes' => 'too-many']);
    }

    public function test_edit_form_keeps_ordered_product_even_when_it_is_no_longer_visible(): void
    {
        $product = $this->visibleProduct();
        $order = Order::factory()->create(['status' => OrderStatus::New]);
        $order->items()->create(['product_id' => $product->id, 'qty' => 4, 'unit_price' => 1000]);

        $product->update(['is_visible' => false]);

        $items = $this->itemsByProduct(
            Livewire::test(EditOrder::class, ['record' => $order->getKey()])->get('data.items')
        );

        $this->assertArrayHasKey($product->id, $items);
        $this->assertSame(4, $items[$product->id]['qty']);
    }

    public function test_items_are_editable_only_while_order_is_new(): void
    {
        $product = $this->visibleProduct();

        $new = Order::factory()->create(['status' => OrderStatus::New]);
        $new->items()->create(['product_id' => $product->id, 'qty' => 1, 'unit_price' => 1000]);

        $processing = Order::factory()->create(['status' => OrderStatus::Processing]);
        $processing->items()->create(['product_id' => $product->id, 'qty' => 1, 'unit_price' => 1000]);

        Livewire::test(EditOrder::class, ['record' => $new->getKey()])
            ->assertFormFieldEnabled('items');

        Livewire::test(EditOrder::class, ['record' => $processing->getKey()])
            ->assertFormFieldDisabled('items');
    }

    public function test_payments_are_hidden_for_new_orders_and_visible_otherwise(): void
    {
        $product = $this->visibleProduct();

        $new = Order::factory()->create(['status' => OrderStatus::New]);
        $new->items()->create(['product_id' => $product->id, 'qty' => 1, 'unit_price' => 1000]);

        $processing = Order::factory()->create(['status' => OrderStatus::Processing]);
        $processing->items()->create(['product_id' => $product->id, 'qty' => 1, 'unit_price' => 1000]);

        Livewire::test(EditOrder::class, ['record' => $new->getKey()])
            ->assertFormFieldHidden('payments');

        Livewire::test(EditOrder::class, ['record' => $processing->getKey()])
            ->assertFormFieldVisible('payments');
    }

    public function test_editing_a_new_order_updates_item_quantity(): void
    {
        $product = $this->visibleProduct();
        $order = Order::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'status' => OrderStatus::New,
        ]);
        $order->items()->create(['product_id' => $product->id, 'qty' => 1, 'unit_price' => 1000]);

        $page = Livewire::test(EditOrder::class, ['record' => $order->getKey()]);

        foreach ($page->get('data.items') as $key => $item) {
            if ($item['product_id'] === $product->id) {
                $page->set("data.items.{$key}.qty", 3);
            }
        }

        $page->call('save')->assertHasNoFormErrors();

        $this->assertSame(3, $order->items()->where('product_id', $product->id)->firstOrFail()->qty);
    }

    public function test_order_number_is_generated_and_cannot_be_edited(): void
    {
        $page = Livewire::test(CreateOrder::class)->assertFormFieldDisabled('number');

        $this->assertMatchesRegularExpression('/^OR-\d{6}$/', $page->get('data.number'));
    }
}