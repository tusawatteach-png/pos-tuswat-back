<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->string('cpu_gen', 50)->nullable();
            $table->string('ram', 50)->nullable();
            $table->string('storage', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->dropColumn(['cpu_gen', 'ram', 'storage']);
        });
    }
};
