<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Repositories\SaleRepository;
use App\Services\PendingOrderService;
use App\Services\SaleService;
use App\DTO\Sale\CreateSaleDTO;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Tests\TestCase;

class PendingOrderServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone');
            $table->string('status')->default('Active');
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('stock_qty')->default(0);
            $table->decimal('cost_price', 10, 2);
            $table->text('description');
            $table->string('sku');
            $table->integer('min_stock_level')->default(0);
            $table->string('status');
            $table->string('product_type');
            $table->decimal('selling_price', 10, 2);
            $table->timestamps();
        });
        Schema::create('unite', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('symbol');
            $table->timestamps();
        });
        Schema::create('order', function (Blueprint $table) {
            $table->id();
            $table->decimal('total', 12, 2);
            $table->decimal('discount', 12, 2);
            $table->decimal('tax', 12, 2);
            $table->decimal('grand_total', 12, 2);
            $table->string('payment_status');
            $table->string('order_status');
            $table->unsignedBigInteger('customer_id');
            $table->timestamps();
        });
        Schema::create('order_item', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('order_id');
            $table->integer('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->decimal('discount', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();
        });

        $migrationExitCode = Artisan::call('migrate', [
            '--path' => database_path('migrations/2026_11_10_000001_add_pending_order_fields_and_payments.php'),
            '--realpath' => true,
            '--force' => true,
        ]);
        $this->assertSame(0, $migrationExitCode, Artisan::output());
        $migrationExitCode = Artisan::call('migrate', [
            '--path' => database_path('migrations/2026_11_10_000002_add_computer_specs_to_order.php'),
            '--realpath' => true,
            '--force' => true,
        ]);
        $this->assertSame(0, $migrationExitCode, Artisan::output());

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();
        });
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no')->unique();
            $table->uuid('idempotency_key')->unique();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->timestamp('sale_date');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount', 12, 2);
            $table->decimal('tax', 12, 2);
            $table->decimal('total', 12, 2);
            $table->decimal('amount_paid', 12, 2);
            $table->decimal('change_amount', 12, 2);
            $table->string('payment_method');
            $table->string('payment_status');
            $table->string('status');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->timestamps();
        });
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('product_id');
            $table->string('product_name');
            $table->string('sku');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount', 12, 2);
            $table->decimal('tax', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();
        });
        Schema::create('stock_movements_tabel', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('user_id');
            $table->string('type');
            $table->integer('quantity');
            $table->integer('stock_before');
            $table->integer('stock_after');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamps();
        });
        DB::table('users')->insert([
            'id' => 1,
            'name' => 'Cashier',
            'email' => 'cashier@example.test',
            'password' => 'password',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_pending_orders_reserve_stock_without_decrementing_it(): void
    {
        [$customer, $product] = $this->createInventoryFixture(2);
        $this->createOrder($customer, $product, 2);

        $this->assertSame(2, (int) $product->fresh()->stock_qty);
        $this->expectException(ValidationException::class);
        $this->createOrder($customer, $product, 1);
    }

    public function test_deposit_records_partial_payment(): void
    {
        [$customer, $product] = $this->createInventoryFixture(3);
        $order = $this->createOrder($customer, $product, 1);

        $updated = app(PendingOrderService::class)->addDeposit($order->id, [
            'amount' => '5.00',
            'payment_method' => 'cash',
        ]);

        $this->assertSame('partial', $updated->payment_status);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'amount' => '5.00',
            'payment_method' => 'cash',
        ]);
    }

    public function test_pending_order_saves_and_searches_computer_specs(): void
    {
        [$customer, $product] = $this->createInventoryFixture(2);
        $order = app(PendingOrderService::class)->create([
            'customer_id' => $customer->id,
            'expected_pickup_date' => now()->addDays(3)->toDateString(),
            'cpu_gen' => 'I5-3',
            'ram' => '8G',
            'storage' => 'SSD-128',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $this->assertSame('I5-3', $order->cpu_gen);
        $this->assertSame('8G', $order->ram);
        $this->assertSame('SSD-128', $order->storage);
        $this->assertSame([$order->id], app(PendingOrderService::class)->pendingOrders('SSD-128')->getCollection()->pluck('id')->all());
    }

    public function test_staff_can_update_specs_on_a_pending_order(): void
    {
        [$customer, $product] = $this->createInventoryFixture(2);
        $order = $this->createOrder($customer, $product, 1);
        $updated = app(PendingOrderService::class)->updateSpecs($order->id, [
            'cpu_gen' => 'I7',
            'ram' => '16G',
            'storage' => 'SSD-512',
        ]);

        $this->assertSame('I7', $updated->cpu_gen);
        $this->assertSame('16G', $updated->ram);
        $this->assertSame('SSD-512', $updated->storage);
    }

    public function test_regular_sale_cannot_consume_stock_reserved_by_a_pending_order(): void
    {
        [$customer, $product] = $this->createInventoryFixture(1);
        $this->createOrder($customer, $product, 1);

        try {
            (new SaleService(new SaleRepository()))->create(new CreateSaleDTO(
                idempotency_key: (string) Str::uuid(),
                customer_id: null,
                user_id: 1,
                payment_method: 'cash',
                amount_paid: '100.00',
                discount: '0.00',
                items: [['product_id' => $product->id, 'quantity' => 1]],
            ));
            $this->fail('A sale must not consume stock reserved by a pending order.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(1, (int) $product->fresh()->stock_qty);
    }

    public function test_conversion_creates_sale_and_deducts_product_and_warehouse_stock(): void
    {
        [$customer, $product, $warehouse] = $this->createInventoryFixture(3);
        $order = app(PendingOrderService::class)->create([
            'customer_id' => $customer->id,
            'expected_pickup_date' => now()->addDays(3)->toDateString(),
            'note' => 'Customer requested setup.',
            'cpu_gen' => 'I5-3',
            'ram' => '8G',
            'storage' => 'SSD-128',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        app(PendingOrderService::class)->addDeposit($order->id, [
            'amount' => '5.00',
            'payment_method' => 'cash',
        ]);

        $result = app(PendingOrderService::class)->convertToSale($order->id, 1, 'card');

        $this->assertSame('completed', $result['order']->order_status);
        $this->assertSame('paid', $result['order']->payment_status);
        $this->assertSame(1, (int) $product->fresh()->stock_qty);
        $this->assertSame(1, (int) $warehouse->stocks()->where('product_id', $product->id)->value('quantity'));
        $this->assertDatabaseCount('sales', 1);
        $this->assertStringContainsString('CPU / Generation: I5-3', $result['sale']->notes);
        $this->assertStringContainsString('RAM: 8G', $result['sale']->notes);
        $this->assertStringContainsString('Storage: SSD-128', $result['sale']->notes);
        $this->assertStringContainsString('Customer requested setup.', $result['sale']->notes);
        $this->assertDatabaseCount('sale_items', 1);
        $this->assertDatabaseCount('stock_movements_tabel', 1);
    }

    public function test_conversion_rejects_insufficient_warehouse_stock_without_creating_a_sale(): void
    {
        [$customer, $product, $warehouse] = $this->createInventoryFixture(3);
        $order = $this->createOrder($customer, $product, 2);
        $warehouse->stocks()->where('product_id', $product->id)->update(['quantity' => 1]);

        try {
            app(PendingOrderService::class)->convertToSale($order->id, 1);
            $this->fail('Conversion should reject insufficient warehouse stock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertSame('pending', $order->fresh()->order_status);
        $this->assertSame(3, (int) $product->fresh()->stock_qty);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements_tabel', 0);
    }

    public function test_conversion_rejects_insufficient_product_stock_without_creating_a_sale(): void
    {
        [$customer, $product] = $this->createInventoryFixture(3);
        $order = $this->createOrder($customer, $product, 2);
        $product->update(['stock_qty' => 1]);

        try {
            app(PendingOrderService::class)->convertToSale($order->id, 1);
            $this->fail('Conversion should reject insufficient product stock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertSame('pending', $order->fresh()->order_status);
        $this->assertSame(1, (int) $product->fresh()->stock_qty);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements_tabel', 0);
    }

    public function test_cancel_releases_the_reservation_and_pending_list_is_searchable(): void
    {
        [$customer, $product] = $this->createInventoryFixture(1);
        $firstOrder = $this->createOrder($customer, $product, 1);
        $cancelled = app(PendingOrderService::class)->cancel($firstOrder->id);
        $secondOrder = $this->createOrder($customer, $product, 1);

        $this->assertSame('cancelled', $cancelled->order_status);
        $orders = app(PendingOrderService::class)->pendingOrders('Customer Example');
        $this->assertSame([$secondOrder->id], $orders->getCollection()->pluck('id')->all());
    }

    private function createInventoryFixture(int $stock): array
    {
        $customer = Customer::query()->create([
            'name' => 'Customer Example',
            'phone' => '555-0101',
            'status' => 'Active',
        ]);
        $product = Product::query()->create([
            'name' => 'Computer',
            'stock_qty' => $stock,
            'cost_price' => '100.00',
            'description' => 'Computer',
            'sku' => 'COMPUTER-1',
            'min_stock_level' => 0,
            'status' => 'active',
            'product_type' => 'product',
            'selling_price' => '100.00',
        ]);
        $warehouse = Warehouse::query()->create(['is_default' => true]);
        $warehouse->stocks()->create([
            'product_id' => $product->id,
            'quantity' => $stock,
        ]);

        return [$customer, $product, $warehouse];
    }

    private function createOrder(Customer $customer, Product $product, int $quantity): Order
    {
        return app(PendingOrderService::class)->create([
            'customer_id' => $customer->id,
            'expected_pickup_date' => now()->addDays(3)->toDateString(),
            'note' => null,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]],
        ]);
    }
}
