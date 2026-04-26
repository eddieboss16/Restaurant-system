<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources');
            $table->decimal('change_amount', 10, 3);
            $table->enum('type', ['auto_deduction', 'manual_restock', 'manual_correction']);
            $table->string('reason')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users');
            $table->foreignId('order_id')->nullable()->constrained('orders');
            $table->timestamps();

            $table->index(['resource_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_transactions');
    }
};
