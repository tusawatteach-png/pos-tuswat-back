<?php

namespace App\Services;

use App\DTO\StockTransfer\CreateStockTransferDTO;
use App\DTO\StockTransfer\UpdateStockTransferDTO;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\WarehouseStock;
use App\Repositories\Interfaces\StockTransferRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockTransferService
{
    public function __construct(private StockTransferRepositoryInterface $repo) {}

    public function all(array $filters)
    {
        return $this->repo->all($filters);
    }

    public function stats(): array
    {
        return $this->repo->stats();
    }

    public function create(CreateStockTransferDTO $dto): StockTransfer
    {
        $this->ensureDifferentWarehouses($dto->from_warehouse_id, $dto->to_warehouse_id);

        return DB::transaction(function () use ($dto) {
            $transfer = $this->repo->create([
                'transfer_no' => 'TR-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6)),
                'from_warehouse_id' => $dto->from_warehouse_id,
                'to_warehouse_id' => $dto->to_warehouse_id,
                'requested_by' => $dto->requested_by,
                'status' => 'Pending',
                'expected_date' => $dto->expected_date,
                'notes' => $dto->notes,
            ], $dto->items);

            return $transfer;
        });
    }

    public function findById(string $id): StockTransfer
    {
        return $this->repo->findById($id);
    }

    public function update(string $id, UpdateStockTransferDTO $dto): StockTransfer
    {
        $this->ensureDifferentWarehouses($dto->from_warehouse_id, $dto->to_warehouse_id);

        return DB::transaction(function () use ($id, $dto) {
            $transfer = $this->repo->findForUpdate($id);
            $this->ensurePending($transfer);

            return $this->repo->update($transfer, [
                'from_warehouse_id' => $dto->from_warehouse_id,
                'to_warehouse_id' => $dto->to_warehouse_id,
                'expected_date' => $dto->expected_date,
                'notes' => $dto->notes,
            ], $dto->items);
        });
    }

    public function delete(string $id): void
    {
        DB::transaction(function () use ($id) {
            $transfer = $this->repo->findForUpdate($id);
            $this->ensurePending($transfer);
            $this->repo->delete($transfer);
        });
    }

    public function changeStatus(string $id, string $status, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($id, $status, $userId) {
            $transfer = $this->repo->findForUpdate($id);

            if ($transfer->status === 'Pending' && $status === 'In Transit') {
                $this->adjustStock($transfer, $userId, 'dispatch');
                $transfer->dispatched_at = now();
            } elseif ($transfer->status === 'In Transit' && $status === 'Completed') {
                $this->adjustStock($transfer, $userId, 'receive');
                $transfer->completed_at = now();
            } elseif (in_array($status, ['Cancelled'], true) && in_array($transfer->status, ['Pending', 'In Transit'], true)) {
                if ($transfer->status === 'In Transit') {
                    $this->adjustStock($transfer, $userId, 'return');
                }
                $transfer->cancelled_at = now();
            } else {
                throw ValidationException::withMessages([
                    'status' => "Cannot change a {$transfer->status} transfer to {$status}.",
                ]);
            }

            $transfer->status = $status;
            $transfer->save();

            return $this->repo->findById((string) $transfer->id);
        });
    }

    private function adjustStock(StockTransfer $transfer, int $userId, string $operation): void
    {
        $transfer->loadMissing('items');

        foreach ($transfer->items->sortBy('product_id') as $item) {
            $product = Product::query()->lockForUpdate()->findOrFail($item->product_id);
            $warehouseId = $operation === 'receive'
                ? $transfer->to_warehouse_id
                : $transfer->from_warehouse_id;
            $stock = WarehouseStock::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            if ($operation === 'dispatch') {
                if (!$stock || $stock->quantity < $item->quantity || $product->stock_qty < $item->quantity) {
                    throw ValidationException::withMessages([
                        'items' => "Insufficient stock for {$product->name} in the source warehouse.",
                    ]);
                }

                $stockBefore = (int) $product->stock_qty;
                $stock->decrement('quantity', $item->quantity);
                $product->decrement('stock_qty', $item->quantity);
                $movementType = 'transfer_out';
            } elseif ($operation === 'receive') {
                $stockBefore = (int) $product->stock_qty;
                $stock ??= WarehouseStock::create([
                    'warehouse_id' => $warehouseId,
                    'product_id' => $product->id,
                    'quantity' => 0,
                ]);
                $stock->increment('quantity', $item->quantity);
                $product->increment('stock_qty', $item->quantity);
                $movementType = 'transfer_in';
            } else {
                if (!$stock) {
                    $stock = WarehouseStock::create([
                        'warehouse_id' => $warehouseId,
                        'product_id' => $product->id,
                        'quantity' => 0,
                    ]);
                }

                $stockBefore = (int) $product->stock_qty;
                $stock->increment('quantity', $item->quantity);
                $product->increment('stock_qty', $item->quantity);
                $movementType = 'transfer_cancelled';
            }

            $product->refresh();
            StockMovement::create([
                'product_id' => $product->id,
                'user_id' => $userId,
                'type' => $movementType,
                'quantity' => $item->quantity,
                'stock_before' => $stockBefore,
                'stock_after' => (int) $product->stock_qty,
                'reference_type' => 'stock_transfer',
                'reference_id' => $transfer->id,
            ]);
        }
    }

    private function ensurePending(StockTransfer $transfer): void
    {
        if ($transfer->status !== 'Pending') {
            throw ValidationException::withMessages([
                'transfer' => 'Only pending transfers can be edited or deleted.',
            ]);
        }
    }

    private function ensureDifferentWarehouses(int $fromWarehouseId, int $toWarehouseId): void
    {
        if ($fromWarehouseId === $toWarehouseId) {
            throw ValidationException::withMessages([
                'to_warehouse_id' => 'The source and destination warehouses must be different.',
            ]);
        }
    }
}
