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
        Schema::create('monitors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');
            $table->integer('interval')->default(60);
            $table->integer('timeout')->default(30);
            $table->integer('expected_status_code')->default(200);
            $table->string('keyword')->nullable();
            $table->string('status')->default('up');
            $table->timestamp('last_checked_at')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('public_token')->unique();
            $table->string('webhook_url')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitors');
    }
};
