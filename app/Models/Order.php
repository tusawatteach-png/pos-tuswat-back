<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;
    protected $table="order";
    protected $appends = ['status'];
    protected $fillable = [
        "total",
        "discount",
        "tax",
        "grand_total",
        "payment_status",
        "order_status",
        "expected_pickup_date",
        "note",
        "cpu_gen",
        "ram",
        "storage",
        "customer_id",
    ];

    protected $casts = [
        'expected_pickup_date' => 'date:Y-m-d',
        'total' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'grand_total' => 'decimal:2',
    ];

    public function getStatusAttribute(): ?string
    {
        return $this->attributes['order_status'] ?? null;
    }

    public function setStatusAttribute(string $value): void
    {
        $this->attributes['order_status'] = $value;
    }

    public function customer(){
        return $this->belongsTo(Customer::class);
    }

    public function orderitems(){
        return $this->hasMany(OrderItem::class);
    }

    public function items(){
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function payments(){
        return $this->hasMany(OrderPayment::class, 'order_id');
    }

    public function orderPaymentMethods(){
        return $this->hasMany(OrderPaymentMethod::class);
    }

}
