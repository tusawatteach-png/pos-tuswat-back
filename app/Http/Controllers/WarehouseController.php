<?php

namespace App\Http\Controllers;

use App\DTO\Warehouse\CreateWarehouseDTO;
use App\Http\Requests\Warehouse\StoreWarehouseRequest;
use App\Services\WarehouseService;
use Illuminate\Http\JsonResponse;

class WarehouseController extends Controller
{
    public function __construct(private WarehouseService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->service->all()]);
    }

    public function store(StoreWarehouseRequest $request): JsonResponse
    {
        $data = $request->validated();
        $warehouse = $this->service->create(new CreateWarehouseDTO(
            name: $data['name'],
            code: $data['code'] ?? null,
            address: $data['address'] ?? null,
        ));

        return response()->json(['status' => 'success', 'data' => $warehouse], 201);
    }

    public function stocks(string $id): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->service->stocks($id)]);
    }
}
