<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Unite;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PendingOrderService
{
    private const MAX_TOTAL_CENTS = 999999999999;

    public function pendingOrders(string $search = '', int $perPage = 100): LengthAwarePaginator
    {
        $query = Order::query()
            ->with(['customer', 'items.product', 'payments'])
            ->where('order_status', 'pending');

        if ($search !== '') {
            $query->where(function ($orders) use ($search) {
                $orders
                    ->whereHas('customer', function ($customers) use ($search) {
                        $customers
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('phone', 'like', '%'.$search.'%');
                    })
                    ->orWhereHas('items.product', function ($products) use ($search) {
                        $products
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('sku', 'like', '%'.$search.'%');
                    })
                    ->orWhere('cpu_gen', 'like', '%'.$search.'%')
                    ->orWhere('ram', 'like', '%'.$search.'%')
                    ->orWhere('storage', 'like', '%'.$search.'%');
            });
        }

        return $query->latest('id')->paginate($perPage);
    }

    public function create(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($data['customer_id']);
            if (strtolower((string) $customer->status) !== 'active') {
                throw ValidationException::withMessages([
                    'customer_id' => 'The selected customer is not active.',
                ]);
            }

            $items = collect($data['items'])->keyBy(fn (array $item) => (int) $item['product_id']);
            $productIds = $items->keys()->map(fn ($id) => (int) $id)->sort()->values()->all();
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $reserved = $this->reservedQuantities($productIds);
            $subtotalCents = 0;
            $orderItems = [];

            foreach ($items as $productId => $item) {
                /** @var Product|null $product */
                $product = $products->get((int) $productId);
                if (! $product || strtolower((string) $product->status) !== 'active') {
                    throw ValidationException::withMessages([
                        'items' => "Product {$productId} is unavailable for an order.",
                    ]);
                }

                $quantity = (int) $item['quantity'];
                $available = (int) $product->stock_qty - (int) ($reserved[$productId] ?? 0);
                if ($quantity > $available) {
                    throw ValidationException::withMessages([
                        'items' => "Insufficient available stock for {$product->name}. Available quantity: ".max(0, $available).'.',
                    ]);
                }

                $unitPriceCents = self::toCents((string) $product->selling_price);
                $lineSubtotalCents = $unitPriceCents * $quantity;
                if ($lineSubtotalCents > self::MAX_TOTAL_CENTS - $subtotalCents) {
                    throw ValidationException::withMessages([
                        'items' => 'The order total exceeds the supported amount.',
                    ]);
                }

                $subtotalCents += $lineSubtotalCents;
                $orderItems[] = [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $this->defaultUnitId(),
                    'unit_price_snapshot' => self::fromCents($unitPriceCents),
                    'discount' => '0.00',
                    'subtotal' => self::fromCents($lineSubtotalCents),
                ];
            }

            $discountCents = self::toCents((string) ($data['discount'] ?? '0'));
            if ($discountCents > $subtotalCents) {
                throw ValidationException::withMessages([
                    'discount' => 'Discount cannot exceed the order subtotal.',
                ]);
            }

            $order = Order::query()->create([
                'total' => self::fromCents($subtotalCents),
                'discount' => self::fromCents($discountCents),
                'tax' => '0.00',
                'grand_total' => self::fromCents($subtotalCents - $discountCents),
                'payment_status' => 'unpaid',
                'order_status' => 'pending',
                'customer_id' => $customer->id,
                'expected_pickup_date' => $data['expected_pickup_date'],
                'note' => $data['note'] ?? null,
                'cpu_gen' => $data['cpu_gen'] ?? null,
                'ram' => $data['ram'] ?? null,
                'storage' => $data['storage'] ?? null,
            ]);
            $order->items()->createMany($orderItems);

            return $order->load(['customer', 'items.product', 'payments']);
        });
    }

    public function updateSpecs(int $orderId, array $specs): Order
    {
        return DB::transaction(function () use ($orderId, $specs) {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $this->ensurePending($order);
            $order->fill([
                'cpu_gen' => $specs['cpu_gen'] ?? null,
                'ram' => $specs['ram'] ?? null,
                'storage' => $specs['storage'] ?? null,
            ]);
            $order->save();

            return $order->load(['customer', 'items.product', 'payments']);
        });
    }

    public function addDeposit(int $orderId, array $data): Order
    {
        return DB::transaction(function () use ($orderId, $data) {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $this->ensurePending($order);

            $amountCents = self::toCents((string) $data['amount']);
            $paidCents = OrderPayment::query()
                ->where('order_id', $order->id)
                ->get(['amount'])
                ->sum(fn (OrderPayment $payment) => self::toCents((string) $payment->amount));
            $balanceCents = self::toCents((string) $order->grand_total) - $paidCents;

            if ($amountCents >= $balanceCents) {
                throw ValidationException::withMessages([
                    'amount' => 'A deposit must be less than the remaining balance. Collect the final payment when converting the order.',
                ]);
            }

            $order->payments()->create([
                'amount' => self::fromCents($amountCents),
                'payment_method' => $data['payment_method'],
                'transaction_id' => $data['transaction_id'] ?? null,
                'paid_at' => now(),
            ]);
            $order->payment_status = 'partial';
            $order->save();

            return $order->load(['customer', 'items.product', 'payments']);
        });
    }

    public function convertToSale(int $orderId, int $userId, string $paymentMethod = 'cash'): array
    {
        $result = DB::transaction(function () use ($orderId, $userId, $paymentMethod) {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $this->ensurePending($order);
            $items = $order->items()->with('product')->orderBy('product_id')->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'The pending order has no items.',
                ]);
            }

            $quantities = $items->groupBy('product_id')->map->sum('quantity');
            $productIds = $quantities->keys()->map(fn ($id) => (int) $id)->sort()->values()->all();
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $reserved = $this->reservedQuantities($productIds);
            $warehouseStocks = WarehouseStock::query()
                ->whereIn('product_id', $productIds)
                ->orderBy('product_id')
                ->orderBy('warehouse_id')
                ->lockForUpdate()
                ->get()
                ->groupBy('product_id');

            foreach ($quantities as $productId => $quantity) {
                /** @var Product|null $product */
                $product = $products->get((int) $productId);
                if (! $product || strtolower((string) $product->status) !== 'active') {
                    throw ValidationException::withMessages([
                        'items' => "Product {$productId} is unavailable for sale.",
                    ]);
                }

                $otherReservations = max(0, (int) ($reserved[$productId] ?? 0) - (int) $quantity);
                if ((int) $product->stock_qty - $otherReservations < (int) $quantity) {
                    throw ValidationException::withMessages([
                        'items' => "Insufficient available stock for {$product->name}.",
                    ]);
                }

                $warehouseQuantity = (int) ($warehouseStocks->get($productId, collect())->sum('quantity'));
                if ($warehouseQuantity - $otherReservations < (int) $quantity) {
                    throw ValidationException::withMessages([
                        'items' => "Insufficient available warehouse stock for {$product->name}.",
                    ]);
                }
            }

            $saleItems = [];
            $subtotalCents = 0;
            foreach ($items as $item) {
                $product = $products->get((int) $item->product_id);
                $unitPrice = $item->unit_price_snapshot !== null
                    ? (string) $item->unit_price_snapshot
                    : (string) $product->selling_price;
                $unitPriceCents = self::toCents($unitPrice);
                $lineSubtotalCents = $unitPriceCents * (int) $item->quantity;
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
                    'quantity' => (int) $item->quantity,
                    'unit_price' => self::fromCents($unitPriceCents),
                    'discount' => '0.00',
                    'tax' => '0.00',
                    'subtotal' => self::fromCents($lineSubtotalCents),
                ];
            }

            $discountCents = self::toCents((string) $order->discount);
            $taxCents = self::toCents((string) $order->tax);
            if ($discountCents > $subtotalCents || $subtotalCents - $discountCents + $taxCents !== self::toCents((string) $order->grand_total)) {
                throw ValidationException::withMessages([
                    'order' => 'The pending order totals do not match its saved items.',
                ]);
            }

            $totalCents = self::toCents((string) $order->grand_total);
            $paidCents = OrderPayment::query()
                ->where('order_id', $order->id)
                ->get(['amount'])
                ->sum(fn (OrderPayment $payment) => self::toCents((string) $payment->amount));

            $saleNotes = collect([
                $order->note,
                $order->cpu_gen ? 'CPU / Generation: '.$order->cpu_gen : null,
                $order->ram ? 'RAM: '.$order->ram : null,
                $order->storage ? 'Storage: '.$order->storage : null,
            ])->filter()->implode("\n");

            $sale = Sale::query()->create([
                'invoice_no' => $this->generateInvoiceNo(),
                'idempotency_key' => (string) Str::uuid(),
                'customer_id' => $order->customer_id,
                'user_id' => $userId,
                'sale_date' => now(),
                'subtotal' => self::fromCents($subtotalCents),
                'discount' => self::fromCents($discountCents),
                'tax' => self::fromCents($taxCents),
                'total' => self::fromCents($totalCents),
                'amount_paid' => self::fromCents($totalCents),
                'change_amount' => '0.00',
                'payment_method' => $paymentMethod,
                'payment_status' => 'paid',
                'status' => 'completed',
                'notes' => $saleNotes !== '' ? $saleNotes : null,
            ]);
            $sale->items()->createMany($saleItems);

            $defaultWarehouseId = Warehouse::query()->where('is_default', true)->value('id');
            foreach ($quantities as $productId => $quantity) {
                /** @var Product $product */
                $product = $products->get((int) $productId);
                $stockBefore = (int) $product->stock_qty;
                $product->decrement('stock_qty', (int) $quantity);
                $product->refresh();

                $warehouseRows = $warehouseStocks->get($productId)->sortBy(
                    fn (WarehouseStock $stock) => $stock->warehouse_id == $defaultWarehouseId
                        ? 0
                        : (int) $stock->warehouse_id
                );
                $remaining = (int) $quantity;
                foreach ($warehouseRows as $stock) {
                    if ($remaining === 0) {
                        break;
                    }
                    $deduction = min($remaining, (int) $stock->quantity);
                    if ($deduction > 0) {
                        $stock->decrement('quantity', $deduction);
                        $remaining -= $deduction;
                    }
                }

                StockMovement::query()->create([
                    'product_id' => $product->id,
                    'user_id' => $userId,
                    'type' => 'sale',
                    'quantity' => -(int) $quantity,
                    'stock_before' => $stockBefore,
                    'stock_after' => (int) $product->stock_qty,
                    'reference_type' => 'sale',
                    'reference_id' => $sale->id,
                ]);
            }

            $balanceCents = $totalCents - $paidCents;
            if ($balanceCents > 0) {
                $order->payments()->create([
                    'amount' => self::fromCents($balanceCents),
                    'payment_method' => $paymentMethod,
                    'paid_at' => now(),
                ]);
            }
            $order->order_status = 'completed';
            $order->payment_status = 'paid';
            $order->save();

            return [
                'sale' => $sale->load(['customer', 'cashier', 'items']),
                'order' => $order->load(['customer', 'items.product', 'payments']),
            ];
        });

        Cache::forget('product_state');
        if (Cache::has('products_cache_version')) {
            Cache::increment('products_cache_version');
        } else {
            Cache::put('products_cache_version', 2);
        }

        return $result;
    }

    public function cancel(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            if ($order->order_status === 'cancelled') {
                return $order->load(['customer', 'items.product', 'payments']);
            }
            $this->ensurePending($order);
            $order->order_status = 'cancelled';
            $order->save();

            return $order->load(['customer', 'items.product', 'payments']);
        });
    }

    private function reservedQuantities(array $productIds): array
    {
        return OrderItem::query()
            ->selectRaw('product_id, SUM(quantity) as reserved_quantity')
            ->whereIn('product_id', $productIds)
            ->whereHas('order', fn ($query) => $query->where('order_status', 'pending'))
            ->groupBy('product_id')
            ->pluck('reserved_quantity', 'product_id')
            ->all();
    }

    private function defaultUnitId(): int
    {
        $unit = Unite::query()->orderBy('id')->first();
        if (! $unit) {
            $unit = Unite::query()->create([
                'name' => 'Unit',
                'symbol' => 'unit',
            ]);
        }

        return (int) $unit->id;
    }

    private function ensurePending(Order $order): void
    {
        if ($order->order_status !== 'pending') {
            throw ValidationException::withMessages([
                'order' => 'Only pending orders can be changed.',
            ]);
        }
    }

    private function generateInvoiceNo(): string
    {
        do {
            $invoiceNo = 'POS-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (Sale::query()->where('invoice_no', $invoiceNo)->exists());

        return $invoiceNo;
    }

    private static function toCents(string $amount): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw ValidationException::withMessages([
                'amount' => 'Amounts must be non-negative values with up to two decimal places.',
            ]);
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private static function fromCents(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
