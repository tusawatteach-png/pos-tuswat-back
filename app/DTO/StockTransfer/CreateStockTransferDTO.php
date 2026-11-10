<?php

namespace App\DTO\StockTransfer;

class CreateStockTransferDTO
{
    public function __construct(
        public int $from_warehouse_id,
        public int $to_warehouse_id,
        public int $requested_by,
        public array $items,
        public ?string $expected_date = null,
        public ?string $notes = null,
    ) {}
}
