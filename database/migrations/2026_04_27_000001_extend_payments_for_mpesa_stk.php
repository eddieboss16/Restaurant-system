<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('phone_number', 15)->nullable()->after('amount');
            $table->enum('status', ['pending', 'completed', 'failed'])
                ->default('completed')
                ->after('phone_number');
            $table->string('mpesa_checkout_request_id')->nullable()->after('mpesa_code');
            $table->string('mpesa_merchant_request_id')->nullable()->after('mpesa_checkout_request_id');
            $table->integer('mpesa_result_code')->nullable()->after('mpesa_merchant_request_id');
            $table->string('mpesa_result_desc')->nullable()->after('mpesa_result_code');

            $table->index('mpesa_checkout_request_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['mpesa_checkout_request_id']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'phone_number',
                'status',
                'mpesa_checkout_request_id',
                'mpesa_merchant_request_id',
                'mpesa_result_code',
                'mpesa_result_desc',
            ]);
        });
    }
};
