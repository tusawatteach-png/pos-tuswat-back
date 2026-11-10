<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->date('expected_pickup_date')->nullable();
            $table->text('note')->nullable();
        });

        Schema::table('order_item', function (Blueprint $table) {
            $table->decimal('unit_price_snapshot', 12, 2)->nullable();
        });

        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('order')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method');
            $table->string('transaction_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');

        Schema::table('order_item', function (Blueprint $table) {
            $table->dropColumn('unit_price_snapshot');
        });

        Schema::table('order', function (Blueprint $table) {
            $table->dropColumn(['expected_pickup_date', 'note']);
        });
    }
};
