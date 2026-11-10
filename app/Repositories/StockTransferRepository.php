<?php

namespace App\Repositories;

use App\Models\StockTransfer;
use App\Repositories\Interfaces\StockTransferRepositoryInterface;

class StockTransferRepository implements StockTransferRepositoryInterface
{
    public function all(array $filters)
    {
        $query = StockTransfer::query()
            ->with(['sourceWarehouse', 'destinationWarehouse', 'requester', 'items.product'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['from_warehouse_id'] ?? null, fn ($query, $id) => $query->where('from_warehouse_id', $id))
            ->when($filters['to_warehouse_id'] ?? null, fn ($query, $id) => $query->where('to_warehouse_id', $id))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('transfer_no', 'like', '%' . $search . '%')
                        ->orWhereHas('sourceWarehouse', fn ($query) => $query->where('name', 'like', '%' . $search . '%'))
                        ->orWhereHas('destinationWarehouse', fn ($query) => $query->where('name', 'like', '%' . $search . '%'))
                        ->orWhereHas('requester', fn ($query) => $query->where('name', 'like', '%' . $search . '%'));
                });
            })
            ->latest();

        return $query->paginate($filters['per_page'] ?? 10);
    }

    public function create(array $data, array $items): StockTransfer
    {
        $transfer = StockTransfer::create($data);
        $transfer->items()->createMany($items);

        return $transfer->load(['sourceWarehouse', 'destinationWarehouse', 'requester', 'items.product']);
    }

    public function findById(string $id): StockTransfer
    {
        return StockTransfer::with(['sourceWarehouse', 'destinationWarehouse', 'requester', 'items.product'])
            ->findOrFail($id);
    }

    public function findForUpdate(string $id): StockTransfer
    {
        return StockTransfer::query()->lockForUpdate()->with('items')->findOrFail($id);
    }

    public function update(StockTransfer $transfer, array $data, array $items): StockTransfer
    {
        $transfer->update($data);
        $transfer->items()->delete();
        $transfer->items()->createMany($items);

        return $transfer->fresh(['sourceWarehouse', 'destinationWarehouse', 'requester', 'items.product']);
    }

    public function delete(StockTransfer $transfer): void
    {
        $transfer->delete();
    }

    public function stats(): array
    {
        $counts = StockTransfer::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total' => (int) $counts->sum(),
            'pending' => (int) ($counts['Pending'] ?? 0),
            'in_transit' => (int) ($counts['In Transit'] ?? 0),
            'completed' => (int) ($counts['Completed'] ?? 0),
            'cancelled' => (int) ($counts['Cancelled'] ?? 0),
        ];
    }
}
