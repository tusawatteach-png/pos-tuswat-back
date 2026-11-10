<?php

namespace App\DTO\Sale;

class CreateSaleDTO
{
    public function __construct(
        public string $idempotency_key,
        public ?int $customer_id,
        public int $user_id,
        public string $payment_method,
        public string $amount_paid,
        public string $discount,
        public array $items,
        public ?string $notes = null,
    ) {}
}
