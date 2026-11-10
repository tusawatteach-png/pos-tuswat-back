<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_no',
        'from_warehouse_id',
        'to_warehouse_id',
        'requested_by',
        'status',
        'expected_date',
        'notes',
        'dispatched_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'expected_date' => 'date:Y-m-d',
        'dispatched_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function sourceWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function destinationWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function items()
    {
        return $this->hasMany(StockTransferItem::class);
    }
}
