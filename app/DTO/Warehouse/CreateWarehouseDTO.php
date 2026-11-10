<?php

namespace App\DTO\Warehouse;

class CreateWarehouseDTO
{
    public function __construct(
        public string $name,
        public ?string $code = null,
        public ?string $address = null,
    ) {}
}
