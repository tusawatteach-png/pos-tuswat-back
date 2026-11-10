<?php

namespace App\Repositories\Interfaces;

use App\Models\Warehouse;

interface WarehouseRepositoryInterface
{
    public function all();
    public function create(array $data): Warehouse;
    public function findById(string $id): Warehouse;
    public function stocks(string $id);
}
