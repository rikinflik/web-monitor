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
        Schema::create('seo_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('www_redirect')->default('none');
            $table->string('https_redirect')->default('none');
            $table->string('trailing_slash_redirect')->default('none');
            $table->boolean('robots_ok')->default(false);
            $table->boolean('sitemap_ok')->default(false);
            $table->integer('interval')->default(1440);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_checks');
    }
};
