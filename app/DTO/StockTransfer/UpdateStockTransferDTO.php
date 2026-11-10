<?php

namespace App\DTO\StockTransfer;

class UpdateStockTransferDTO
{
    public function __construct(
        public int $from_warehouse_id,
        public int $to_warehouse_id,
        public array $items,
        public ?string $expected_date = null,   
        public ?string $notes = null,
    ) {}
}
