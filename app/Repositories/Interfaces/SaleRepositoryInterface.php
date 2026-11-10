<?php

namespace App\Repositories\Interfaces;

use App\Models\Sale;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface SaleRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    public function findById(int $id): Sale;

    public function findByIdempotencyKey(string $key): ?Sale;

    public function invoiceExists(string $invoiceNo): bool;

    public function lockProducts(array $productIds): Collection;

    public function create(array $data): Sale;

    public function createItems(Sale $sale, array $items): void;
}
