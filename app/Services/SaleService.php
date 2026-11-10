<?php

namespace App\Services;

use App\DTO\Sale\CreateSaleDTO;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Repositories\Interfaces\SaleRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    private const MAX_TOTAL_CENTS = 999999999999;

    public function __construct(private SaleRepositoryInterface $repo) {}

    public function all(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repo->paginate($filters, $perPage);
    }

    public function findById(int $id): Sale
    {
        return $this->repo->findById($id);
    }

    public function create(CreateSaleDTO $dto): Sale
    {
        try {
            $sale = DB::transaction(function () use ($dto) {
                $existingSale = $this->repo->findByIdempotencyKey($dto->idempotency_key);
                if ($existingSale) {
                    return $existingSale;
                }

                if ($dto->customer_id !== null) {
                    $customer = Customer::query()->lockForUpdate()->find($dto->customer_id);
                    if (! $customer || strtolower((string) $customer->status) !== 'active') {
                        throw ValidationException::withMessages([
                            'customer_id' => 'The selected customer is not available for a sale.',
                        ]);
                    }
                }

                $itemsById = collect($dto->items)->keyBy(fn (array $item) => (int) $item['product_id']);
                $products = $this->repo->lockProducts($itemsById->keys()->all())->keyBy('id');
                $subtotalCents = 0;
                $saleItems = [];
                $stockChanges = [];

                foreach ($itemsById as $productId => $item) {
                    /** @var Product|null $product */
                    $product = $products->get((int) $productId);
                    if (! $product || strtolower((string) $product->status) !== 'active') {
                        throw ValidationException::withMessages([
                            'items' => "Product {$productId} is unavailable for sale.",
                        ]);
                    }

                    $quantity = (int) $item['quantity'];
                    if ($quantity > (int) $product->stock_qty) {
                        throw ValidationException::withMessages([
                            'items' => "Insufficient stock for {$product->name}. Available quantity: {$product->stock_qty}.",
                        ]);
                    }

                    $unitPriceCents = self::toCents((string) $product->selling_price);
                    $lineSubtotalCents = $unitPriceCents * $quantity;
                    if ($lineSubtotalCents > self::MAX_TOTAL_CENTS - $subtotalCents) {
                        throw ValidationException::withMessages([
                            'items' => 'The sale total exceeds the supported amount.',
                        ]);
                    }
                    $subtotalCents += $lineSubtotalCents;
                    $saleItems[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'quantity' => $quantity,
                        'unit_price' => self::fromCents($unitPriceCents),
                        'discount' => '0.00',
                        'tax' => '0.00',
                        'subtotal' => self::fromCents($lineSubtotalCents),
                    ];
                    $stockChanges[] = [
                        'product' => $product,
                        'quantity' => $quantity,
                    ];
                }

                $discountCents = self::toCents($dto->discount);
                if ($discountCents > $subtotalCents) {
                    throw ValidationException::withMessages([
                        'discount' => 'Discount cannot exceed the sale subtotal.',
                    ]);
                }

                $taxCents = 0;
                $totalCents = $subtotalCents - $discountCents;
                $amountPaidCents = self::toCents($dto->amount_paid);
                if ($amountPaidCents < $totalCents) {
                    throw ValidationException::withMessages([
                        'amount_paid' => 'Amount paid must be at least the sale total.',
                    ]);
                }

                $invoiceNo = $this->generateInvoiceNo();
                $sale = $this->repo->create([
                    'invoice_no' => $invoiceNo,
                    'idempotency_key' => $dto->idempotency_key,
                    'customer_id' => $dto->customer_id,
                    'user_id' => $dto->user_id,
                    'sale_date' => now(),
                    'subtotal' => self::fromCents($subtotalCents),
                    'discount' => self::fromCents($discountCents),
                    'tax' => self::fromCents($taxCents),
                    'total' => self::fromCents($totalCents),
                    'amount_paid' => self::fromCents($amountPaidCents),
                    'change_amount' => self::fromCents($amountPaidCents - $totalCents),
                    'payment_method' => $dto->payment_method,
                    'payment_status' => 'paid',
                    'status' => 'completed',
                    'notes' => $dto->notes,
                ]);

                $this->repo->createItems($sale, $saleItems);
                foreach ($stockChanges as $change) {
                    /** @var Product $product */
                    $product = $change['product'];
                    $quantity = $change['quantity'];
                    $stockBefore = (int) $product->stock_qty;
                    $product->decrement('stock_qty', $quantity);
                    $product->refresh();

                    StockMovement::query()->create([
                        'product_id' => $product->id,
                        'user_id' => $dto->user_id,
                        'type' => 'sale',
                        'quantity' => -$quantity,
                        'stock_before' => $stockBefore,
                        'stock_after' => (int) $product->stock_qty,
                        'reference_type' => 'sale',
                        'reference_id' => $sale->id,
                    ]);
                }

                return $this->repo->findById((int) $sale->id);
            });
        } catch (QueryException $exception) {
            $existingSale = $this->repo->findByIdempotencyKey($dto->idempotency_key);
            if ($existingSale) {
                return $existingSale;
            }

            throw $exception;
        }

        Cache::forget('product_state');
        if (Cache::has('products_cache_version')) {
            Cache::increment('products_cache_version');
        } else {
            Cache::put('products_cache_version', 2);
        }

        return $sale;
    }

    private function generateInvoiceNo(): string
    {
        do {
            $invoiceNo = 'POS-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
        } while ($this->repo->invoiceExists($invoiceNo));

        return $invoiceNo;
    }

    private static function toCents(string $amount): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw ValidationException::withMessages([
                'amount' => 'Amounts must be non-negative values with up to two decimal places.',
            ]);
        }

        $whole = (int) $matches[1];
        $fraction = (int) str_pad($matches[2] ?? '', 2, '0');

        return ($whole * 100) + $fraction;
    }

    private static function fromCents(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
