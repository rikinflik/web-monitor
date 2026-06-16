<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('monitor_logs', function (Blueprint $table) {
            $table->string('error_message')->nullable()->after('status_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monitor_logs', function (Blueprint $table) {
            $table->dropColumn('error_message');
        });
    }
};
