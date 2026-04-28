<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the hot paths surfaced by the Reports tab and waiter Today
 * strip. Existing schema already covers (waiter_id, status) on sessions
 * and (status) on orders -- these add the date-range and join paths.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_sessions', function (Blueprint $table) {
            // For "paid sessions in this date range" -- daily/monthly reports.
            $table->index(['status', 'closed_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // For top-items aggregation: join orders to sessions then filter
            // by orders.status='delivered'. Covers the join lookup.
            $table->index(['session_id', 'status']);
        });

        Schema::table('cancellation_logs', function (Blueprint $table) {
            // For "cancellations today" count.
            $table->index('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_sessions', function (Blueprint $table) {
            $table->dropIndex(['status', 'closed_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['session_id', 'status']);
        });

        Schema::table('cancellation_logs', function (Blueprint $table) {
            $table->dropIndex(['cancelled_at']);
        });
    }
};
