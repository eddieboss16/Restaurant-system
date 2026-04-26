<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waiter_id')->constrained('users')->cascadeOnDelete();
            $table->string('customer_label')->nullable();
            $table->enum('status', ['open', 'ordered', 'served', 'billed', 'paid'])->default('open');
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['waiter_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_sessions');
    }
};
