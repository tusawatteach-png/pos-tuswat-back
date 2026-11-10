<?php

namespace App\Services;

use App\DTO\Warehouse\CreateWarehouseDTO;
use App\Repositories\Interfaces\WarehouseRepositoryInterface;
use App\Models\Warehouse;

class WarehouseService
{
    public function __construct(private WarehouseRepositoryInterface $repo) {}

    public function all()
    {
        return $this->repo->all();
    }

    public function create(CreateWarehouseDTO $dto): Warehouse
    {
        return $this->repo->create([
            'name' => $dto->name,
            'code' => $dto->code,
            'address' => $dto->address,
            'is_active' => true,
            'is_default' => false,
        ]);
    }

    public function findById(string $id): Warehouse
    {
        return $this->repo->findById($id);
    }

    public function stocks(string $id)
    {
        $this->repo->findById($id);

        return $this->repo->stocks($id);
    }
}
