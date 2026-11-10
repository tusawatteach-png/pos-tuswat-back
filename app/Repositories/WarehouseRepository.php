<?php

namespace App\Repositories;

use App\Models\Warehouse;
use App\Repositories\Interfaces\WarehouseRepositoryInterface;
use Illuminate\Support\Facades\DB;

class WarehouseRepository implements WarehouseRepositoryInterface
{
    public function all()
    {
        return Warehouse::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get();
    }

    public function create(array $data): Warehouse
    {
        return DB::transaction(function () use ($data) {
            $warehouse = Warehouse::create($data);
            $now = now();

            DB::table('warehouse_stocks')->insertUsing(
                ['warehouse_id', 'product_id', 'quantity', 'created_at', 'updated_at'],
                DB::table('products')->selectRaw(
                    '? as warehouse_id, id as product_id, 0 as quantity, ? as created_at, ? as updated_at',
                    [$warehouse->id, $now, $now],
                ),
            );

            return $warehouse;
        });
    }

    public function findById(string $id): Warehouse
    {
        return Warehouse::findOrFail($id);
    }

    public function stocks(string $id)
    {
        return DB::table('products')
            ->leftJoin('warehouse_stocks', function ($join) use ($id) {
                $join->on('warehouse_stocks.product_id', '=', 'products.id')
                    ->where('warehouse_stocks.warehouse_id', '=', $id);
            })
            ->select([
                'products.id',
                'products.name',
                'products.sku',
                'products.stock_qty',
                DB::raw('COALESCE(warehouse_stocks.quantity, 0) as warehouse_stock'),
            ])
            ->orderBy('products.name')
            ->get();
    }
}
