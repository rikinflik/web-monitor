<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_checks', function (Blueprint $table) {
            // Speeds up the periodic-scan query gated on last_checked_at.
            $table->index('last_checked_at');
        });

        Schema::table('seo_check_logs', function (Blueprint $table) {
            // Speeds up: $seoCheck->logs()->latest()->get()
            $table->index(['seo_check_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('seo_checks', function (Blueprint $table) {
            $table->dropIndex(['last_checked_at']);
        });

        Schema::table('seo_check_logs', function (Blueprint $table) {
            $table->dropIndex(['seo_check_id', 'created_at']);
        });
    }
};
