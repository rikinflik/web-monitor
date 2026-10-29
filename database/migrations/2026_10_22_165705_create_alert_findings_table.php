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
        // external_id is a machine identifier (GHSA id, RSS <guid>, or a
        // URL), so dedup must be case-sensitive. Pinned explicitly via
        // column collation rather than inherited from the connection's
        // default (production runs utf8mb4_unicode_ci, which is
        // case-insensitive and would silently merge two distinct ids
        // differing only in case). SQLite has no "utf8mb4_bin" collation —
        // its built-in case-sensitive equivalent is "BINARY".
        $externalIdCollation = Schema::getConnection()->getDriverName() === 'sqlite'
            ? 'BINARY'
            : 'utf8mb4_bin';

        Schema::create('alert_findings', function (Blueprint $table) use ($externalIdCollation) {
            $table->id();
            $table->foreignId('alert_source_id')->constrained()->cascadeOnDelete();
            // external_id stays at 512 (not wider) so the composite unique
            // index below remains within InnoDB's 3072-byte key limit under
            // utf8mb4: 8 (alert_source_id, bigint) + 512 * 4 = 2056 bytes.
            $table->string('external_id', 512)->collation($externalIdCollation);
            // title: GitHub advisory summaries run up to ~1024 chars.
            $table->string('title', 1024);
            // url: external_id falls back to the feed item's link when no
            // GHSA id/guid is present, so url must tolerate arbitrary-length
            // real-world links.
            $table->string('url', 2048);
            $table->string('severity')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            // useCurrent() is required for the DDL to be accepted at all on a
            // production-style MySQL/MariaDB, not a convenience: with
            // explicit_defaults_for_timestamp = 0 the server hands every
            // TIMESTAMP NOT NULL column after the first one an implicit
            // default of '0000-00-00 00:00:00', and a sql_mode carrying
            // NO_ZERO_DATE then rejects the CREATE with errno 1067
            // ("Invalid default value for 'first_seen_at'"). DDEV runs
            // explicit_defaults_for_timestamp = 1, so the broken DDL was
            // accepted locally and only failed on the real deploy.
            // The value is always written explicitly by
            // AlertCheckService::insertFinding() and AlertFindingFactory, so
            // this default is never actually used — it exists to make the
            // column definition legal on both server configurations.
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamps();

            $table->unique(['alert_source_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_findings');
    }
};
