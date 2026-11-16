<?php
namespace App\DTO\Customer;

use Illuminate\Http\UploadedFile;

class CreateCustomerDTO{
    public function __construct(
        public string $name,
        public string $phone,
        public ?string $address,
        public ?string $email = null,
        public ?string $group = 'Standard',
        public ?string $status = 'Active',
        public ?UploadedFile $image = null,
    ){}
}
