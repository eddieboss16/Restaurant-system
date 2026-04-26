<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('customer_sessions');
            $table->enum('method', ['cash', 'mpesa']);
            $table->decimal('amount', 10, 2);
            $table->string('mpesa_code')->nullable();
            $table->foreignId('collected_by')->constrained('users');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
