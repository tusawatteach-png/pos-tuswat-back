<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('warehouses')->insertOrIgnore([
            'name' => 'Phnom Penh Branch',
            'code' => 'PP-BRANCH',
            'is_active' => true,
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $warehouseId = DB::table('warehouses')
            ->where('name', 'Phnom Penh Branch')
            ->value('id');

        if (!$warehouseId && DB::connection()->pretending()) {
            return;
        }

        if (!$warehouseId) {
            throw new RuntimeException('Unable to create the Phnom Penh Branch warehouse.');
        }

        $now = now();
        DB::table('warehouse_stocks')->insertUsing(
            ['warehouse_id', 'product_id', 'quantity', 'created_at', 'updated_at'],
            DB::table('products')
                ->whereNotExists(function (Builder $query) use ($warehouseId) {
                    $query->select(DB::raw(1))
                        ->from('warehouse_stocks')
                        ->whereColumn('warehouse_stocks.product_id', 'products.id')
                        ->where('warehouse_stocks.warehouse_id', $warehouseId);
                })
                ->selectRaw(
                    '? as warehouse_id, id as product_id, 0 as quantity, ? as created_at, ? as updated_at',
                    [$warehouseId, $now, $now],
                ),
        );
    }

    public function down(): void
    {
        $warehouseId = DB::table('warehouses')
            ->where('name', 'Phnom Penh Branch')
            ->value('id');

        if ($warehouseId) {
            DB::table('warehouse_stocks')->where('warehouse_id', $warehouseId)->delete();
            DB::table('warehouses')->where('id', $warehouseId)->delete();
        }
    }
};
