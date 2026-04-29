<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->nullable()->constrained('customer_sessions');
            $table->foreignId('queued_by')->nullable()->constrained('users');
            $table->json('payload');
            $table->enum('status', ['pending', 'printing', 'printed', 'failed'])->default('pending');
            $table->string('error', 500)->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
    }
};
