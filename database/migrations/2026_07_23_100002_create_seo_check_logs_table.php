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
        Schema::create('seo_check_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_check_id')->constrained()->cascadeOnDelete();
            $table->string('www_redirect');
            $table->string('https_redirect');
            $table->string('trailing_slash_redirect');
            $table->boolean('robots_ok');
            $table->boolean('sitemap_ok');
            $table->timestamp('checked_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_check_logs');
    }
};
