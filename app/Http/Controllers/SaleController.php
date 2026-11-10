<?php

namespace App\Http\Controllers;

use App\DTO\Sale\CreateSaleDTO;
use App\Http\Requests\Sale\StoreSaleRequest;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function __construct(private SaleService $saleService) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payment_status' => ['sometimes', 'nullable', 'in:paid,unpaid,partial'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $perPage = (int) ($filters['per_page'] ?? 20);
        unset($filters['page'], $filters['per_page']);

        return response()->json([
            'status' => 'success',
            'data' => $this->saleService->all($filters, $perPage),
        ]);
    }

    public function store(StoreSaleRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $sale = $this->saleService->create(new CreateSaleDTO(
                idempotency_key: $data['idempotency_key'],
                customer_id: isset($data['customer_id']) ? (int) $data['customer_id'] : null,
                user_id: (int) $request->user()->id,
                payment_method: $data['payment_method'],
                amount_paid: (string) $data['amount_paid'],
                discount: (string) ($data['discount'] ?? '0'),
                items: $data['items'],
                notes: $data['notes'] ?? null,
            ));

            return response()->json([
                'status' => 'Sale completed successfully',
                'data' => $sale,
            ], 201);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first(),
                'errors' => $exception->errors(),
            ], 422);
        } catch (\Throwable $exception) {
            Log::error('Sale processing failed', [
                'message' => $exception->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'The sale could not be completed. Please try again.',
            ], 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->saleService->findById((int) $id),
        ]);
    }
}
