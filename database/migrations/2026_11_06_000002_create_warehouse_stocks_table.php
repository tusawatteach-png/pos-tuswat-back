<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();
            $table->unique(['warehouse_id', 'product_id']);
        });

        $warehouseId = DB::table('warehouses')->where('is_default', true)->value('id');
        $now = now();

        DB::table('warehouse_stocks')->insertUsing(
            ['warehouse_id', 'product_id', 'quantity', 'created_at', 'updated_at'],
            DB::table('products')->selectRaw(
                '? as warehouse_id, id as product_id, stock_qty as quantity, ? as created_at, ? as updated_at',
                [$warehouseId, $now, $now],
            ),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stocks');
    }
};
