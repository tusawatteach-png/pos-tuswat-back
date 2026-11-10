<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\Sale;
use App\Repositories\Interfaces\SaleRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class SaleRepository implements SaleRepositoryInterface
{
    private const RELATIONS = ['customer', 'cashier', 'items'];

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Sale::query()->with(self::RELATIONS);

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where('invoice_no', 'like', '%'.$search.'%');
        }

        if (! empty($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('sale_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('sale_date', '<=', $filters['date_to']);
        }

        return $query->latest('sale_date')->latest('id')->paginate($perPage);
    }

    public function findById(int $id): Sale
    {
        return Sale::query()->with(self::RELATIONS)->findOrFail($id);
    }

    public function findByIdempotencyKey(string $key): ?Sale
    {
        return Sale::query()->with(self::RELATIONS)->where('idempotency_key', $key)->first();
    }

    public function invoiceExists(string $invoiceNo): bool
    {
        return Sale::query()->where('invoice_no', $invoiceNo)->exists();
    }

    public function lockProducts(array $productIds): Collection
    {
        return Product::query()
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function create(array $data): Sale
    {
        return Sale::query()->create($data);
    }

    public function createItems(Sale $sale, array $items): void
    {
        $sale->items()->createMany($items);
    }
}
