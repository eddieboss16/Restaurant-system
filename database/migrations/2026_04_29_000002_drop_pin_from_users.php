<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the unused users.pin column.
 *
 * The PIN field was originally seeded for waiters with a vague
 * shift-switcher idea that never got built. Nothing reads it. Removing
 * the dead surface so the staff CRUD doesn't keep prompting for a PIN
 * the system can't actually use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin', 6)->nullable()->after('role');
        });
    }
};
