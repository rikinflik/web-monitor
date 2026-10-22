<?php

use Database\Seeders\AlertSourceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Also installs the six fixed ecosystem rows via AlertSourceSeeder so a
     * plain `artisan migrate` (no --seed) is sufficient: the seeder is the
     * single definition of those rows, kept out of DatabaseSeeder to avoid
     * coupling this feature's install step to provisioning demo users with
     * hardcoded passwords.
     */
    public function up(): void
    {
        Schema::create('alert_sources', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('kind');
            $table->json('config');
            $table->timestamp('last_checked_at')->nullable()->index();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        (new AlertSourceSeeder)->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_sources');
    }
};
