<?php

namespace App\Http\Controllers;

use App\DTO\StockTransfer\CreateStockTransferDTO;
use App\DTO\StockTransfer\UpdateStockTransferDTO;
use App\Http\Requests\StockTransfer\StoreStockTransferRequest;
use App\Http\Requests\StockTransfer\UpdateStockTransferRequest;
use App\Services\StockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockTransferController extends Controller
{
    public function __construct(private StockTransferService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:Pending,In Transit,Completed,Cancelled'],
            'from_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $this->service->all($filters),
            'summary' => $this->service->stats(),
        ]);
    }

    public function requestIndex(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $filters['status'] = 'Pending';

        return response()->json(['status' => 'success', 'data' => $this->service->all($filters)]);
    }

    public function stats(): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->service->stats()]);
    }

    public function summary(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->service->stats(),
        ]);
    }

    public function store(StoreStockTransferRequest $request): JsonResponse
    {
        $data = $request->validated();
        $transfer = $this->service->create(new CreateStockTransferDTO(
            from_warehouse_id: (int) $data['from_warehouse_id'],
            to_warehouse_id: (int) $data['to_warehouse_id'],
            requested_by: (int) $request->user()->id,
            items: $data['items'],
            expected_date: $data['expected_date'] ?? null,
            notes: $data['notes'] ?? null,
        ));

        return response()->json(['status' => 'success', 'data' => $transfer], 201);
    }

    public function requestStore(StoreStockTransferRequest $request): JsonResponse
    {
        return $this->store($request);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->service->findById($id)]);
    }

    public function requestShow(string $id): JsonResponse
    {
        return $this->show($id);
    }

    public function update(UpdateStockTransferRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();
        $transfer = $this->service->update($id, new UpdateStockTransferDTO(
            from_warehouse_id: (int) $data['from_warehouse_id'],
            to_warehouse_id: (int) $data['to_warehouse_id'],
            items: $data['items'],
            expected_date: $data['expected_date'] ?? null,
            notes: $data['notes'] ?? null,
        ));

        return response()->json(['status' => 'success', 'data' => $transfer]);
    }

    public function requestUpdate(UpdateStockTransferRequest $request, string $id): JsonResponse
    {
        return $this->update($request, $id);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);

        return response()->json(['status' => 'success']);
    }

    public function requestDestroy(string $id): JsonResponse
    {
        return $this->destroy($id);
    }

    public function changeStatus(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:In Transit,Completed,Cancelled'],
        ]);

        $transfer = $this->service->changeStatus($id, $data['status'], (int) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $transfer]);
    }
}
