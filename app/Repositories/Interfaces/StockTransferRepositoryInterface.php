<?php

namespace App\Repositories\Interfaces;

use App\Models\StockTransfer;

interface StockTransferRepositoryInterface
{
    public function all(array $filters);
    public function create(array $data, array $items): StockTransfer;
    public function findById(string $id): StockTransfer;
    public function findForUpdate(string $id): StockTransfer;
    public function update(StockTransfer $transfer, array $data, array $items): StockTransfer;
    public function delete(StockTransfer $transfer): void;
    public function stats(): array;
}
