<?php

namespace Tests\Feature;

use App\DTO\StockTransfer\CreateStockTransferDTO;
use App\DTO\Warehouse\CreateWarehouseDTO;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\WarehouseController;
use App\Http\Requests\StockTransfer\StoreStockTransferRequest;
use App\Http\Requests\StockTransfer\UpdateStockTransferRequest;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\StockTransferService;
use App\Services\WarehouseService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class StockTransferTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');

        foreach (['stock_movements_tabel', 'stock_transfer_items', 'stock_transfers', 'warehouse_stocks', 'warehouses', 'migrations', 'products', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
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
        DB::table('products')->insert([
            'name' => 'Legacy Product',
            'stock_qty' => 7,
            'cost_price' => 5,
            'description' => 'Existing stock to initialize at the default warehouse.',
            'sku' => 'LEGACY-STOCK',
            'min_stock_level' => 0,
            'status' => 'active',
            'product_type' => 'product',
            'selling_price' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migrationExitCode = Artisan::call('migrate', [
            '--path' => [
                database_path('migrations/2026_11_06_000001_create_warehouses_table.php'),
                database_path('migrations/2026_11_06_000002_create_warehouse_stocks_table.php'),
                database_path('migrations/2026_11_06_000003_create_stock_transfers_table.php'),
                database_path('migrations/2026_11_06_000004_create_stock_transfer_items_table.php'),
                database_path('migrations/2026_11_06_000005_seed_phnom_penh_warehouse.php'),
            ],
            '--realpath' => true,
            '--force' => true,
        ]);
        $this->assertSame(0, $migrationExitCode, Artisan::output());

        Schema::create('stock_movements_tabel', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('user_id');
            $table->string('type');
            $table->integer('quantity');
            $table->integer('stock_after');
            $table->integer('stock_before');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_migration_seeds_existing_stock_in_the_default_warehouse(): void
    {
        $product = Product::query()->where('sku', 'LEGACY-STOCK')->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->assertSame(
            7,
            (int) WarehouseStock::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->value('quantity'),
        );
    }

    public function test_new_warehouse_starts_with_zero_stock_for_existing_products(): void
    {
        $warehouse = app(WarehouseService::class)->create(new CreateWarehouseDTO(
            name: 'New Branch',
            code: 'NEW',
        ));
        $product = Product::query()->where('sku', 'LEGACY-STOCK')->firstOrFail();

        $this->assertSame(
            0,
            (int) WarehouseStock::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->value('quantity'),
        );
    }

    public function test_warehouse_api_returns_distinct_main_and_phnom_penh_warehouses(): void
    {
        $response = app(WarehouseController::class)->index()->getData(true);
        $warehouses = $response['data'];

        $this->assertGreaterThanOrEqual(2, count($warehouses));
        $this->assertContains('Main Warehouse', array_column($warehouses, 'name'));
        $this->assertContains('Phnom Penh Branch', array_column($warehouses, 'name'));
        $this->assertCount(count($warehouses), array_unique(array_column($warehouses, 'id')));
    }

    public function test_dispatch_and_receipt_adjust_stock_at_each_warehouse(): void
    {
        [$service, $transfer, $source, $destination, $product] = $this->createTransferFixture();

        $service->changeStatus((string) $transfer->id, 'In Transit', $transfer->requested_by);

        $this->assertSame(6, (int) $source->stocks()->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(6, (int) $product->fresh()->stock_qty);

        $completed = $service->changeStatus((string) $transfer->id, 'Completed', $transfer->requested_by);

        $this->assertSame('Completed', $completed->status);
        $this->assertSame(4, (int) $destination->stocks()->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(10, (int) $product->fresh()->stock_qty);
        $this->assertDatabaseCount('stock_movements_tabel', 2);
    }

    public function test_cancelling_an_in_transit_transfer_returns_stock_to_the_source(): void
    {
        [$service, $transfer, $source, , $product] = $this->createTransferFixture();

        $service->changeStatus((string) $transfer->id, 'In Transit', $transfer->requested_by);
        $cancelled = $service->changeStatus((string) $transfer->id, 'Cancelled', $transfer->requested_by);

        $this->assertSame('Cancelled', $cancelled->status);
        $this->assertSame(10, (int) $source->stocks()->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(10, (int) $product->fresh()->stock_qty);
        $this->assertDatabaseCount('stock_movements_tabel', 2);
    }

    public function test_dispatch_rejects_insufficient_source_stock_without_partial_changes(): void
    {
        [$service, $transfer, $source, , $product] = $this->createTransferFixture(3, 4);

        try {
            $service->changeStatus((string) $transfer->id, 'In Transit', $transfer->requested_by);
            $this->fail('Dispatch should reject a transfer that exceeds source stock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertSame('Pending', $transfer->fresh()->status);
        $this->assertSame(3, (int) $source->stocks()->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(3, (int) $product->fresh()->stock_qty);
        $this->assertDatabaseCount('stock_movements_tabel', 0);
    }

    public function test_transfer_requests_reject_identical_warehouse_ids(): void
    {
        [$service, $transfer, $source] = $this->createTransferFixture();
        $data = [
            'from_warehouse_id' => $source->id,
            'to_warehouse_id' => $source->id,
            'items' => $transfer->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
            ])->all(),
        ];

        foreach ([StoreStockTransferRequest::class, UpdateStockTransferRequest::class] as $requestClass) {
            $validator = Validator::make($data, (new $requestClass())->rules());
            $this->assertTrue($validator->fails());
            $this->assertArrayHasKey('from_warehouse_id', $validator->errors()->toArray());
        }

        try {
            $service->create(new CreateStockTransferDTO(
                from_warehouse_id: $source->id,
                to_warehouse_id: $source->id,
                requested_by: $transfer->requested_by,
                items: $data['items'],
            ));
            $this->fail('Creating a transfer to the same warehouse should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('to_warehouse_id', $exception->errors());
        }
    }

    public function test_list_and_summary_endpoints_return_paginated_transfers_and_counts(): void
    {
        [$service] = $this->createTransferFixture();
        $controller = app(StockTransferController::class);

        $listResponse = $controller->index(Request::create('/api/stock-transfers', 'GET', [
            'status' => 'Pending',
            'per_page' => 5,
        ]));
        $list = $listResponse->getData(true);

        $this->assertSame(200, $listResponse->getStatusCode());
        $this->assertSame(1, $list['data']['total']);
        $this->assertSame(5, $list['data']['per_page']);
        $this->assertSame(1, $list['summary']['pending']);

        $summary = $controller->summary()->getData(true);
        $this->assertSame(1, $summary['data']['total']);
        $this->assertSame(1, $summary['data']['pending']);
        $this->assertSame(0, $summary['data']['completed']);
    }

    private function createTransferFixture(int $sourceQuantity = 10, int $transferQuantity = 4): array
    {
        $source = Warehouse::query()->where('is_default', true)->firstOrFail();
        $destination = app(WarehouseService::class)->create(new CreateWarehouseDTO(
            name: 'Branch ' . uniqid(),
            code: uniqid('BRANCH-'),
        ));
        $user = User::create([
            'name' => 'Transfer User',
            'email' => uniqid('transfer-', true) . '@example.test',
            'password' => 'password',
        ]);
        $product = Product::create([
            'name' => 'Transfer Product',
            'stock_qty' => $sourceQuantity,
            'cost_price' => 5,
            'description' => 'Product used in stock transfer tests.',
            'sku' => uniqid('TRANSFER-'),
            'min_stock_level' => 0,
            'status' => 'active',
            'product_type' => 'product',
            'selling_price' => 10,
        ]);
        WarehouseStock::create([
            'warehouse_id' => $source->id,
            'product_id' => $product->id,
            'quantity' => $sourceQuantity,
        ]);
        WarehouseStock::create([
            'warehouse_id' => $destination->id,
            'product_id' => $product->id,
            'quantity' => 0,
        ]);

        $service = app(StockTransferService::class);
        $transfer = $service->create(new CreateStockTransferDTO(
            from_warehouse_id: $source->id,
            to_warehouse_id: $destination->id,
            requested_by: $user->id,
            items: [
                ['product_id' => $product->id, 'quantity' => $transferQuantity],
            ],
        ));

        return [$service, $transfer, $source, $destination, $product];
    }
}
