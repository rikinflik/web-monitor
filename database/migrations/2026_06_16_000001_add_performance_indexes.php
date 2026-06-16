<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_logs', function (Blueprint $table) {
            // Speeds up: $monitor->logs()->latest()->limit(N)->get()
            $table->index(['monitor_id', 'created_at']);
        });

        Schema::table('monitors', function (Blueprint $table) {
            $table->index('last_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_logs', function (Blueprint $table) {
            $table->dropIndex(['monitor_id', 'created_at']);
        });

        Schema::table('monitors', function (Blueprint $table) {
            $table->dropIndex(['last_checked_at']);
        });
    }
};
