<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 10, 2);
            $table->enum('category', ['supplies', 'salaries', 'utilities', 'rent', 'transport', 'other']);
            $table->string('description', 255);
            $table->date('incurred_on');
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();

            $table->index('incurred_on');
            $table->index(['category', 'incurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
