<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function __construct()
    {
        $this->connection = config('backfill.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->table('backfill_runs', function (Blueprint $table) {
            // Each tenant gets its own cursor, so each gets its own run.
            $table->string('tenant')->nullable()->after('backfill')->index();
        });

        // Ledger mode: for work the database cannot roll back. A row is claimed
        // before the side effect happens and confirmed after, so a crash leaves
        // an unconfirmed claim to investigate rather than a second email.
        $schema->create('backfill_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('backfill');
            $table->string('record_id');
            $table->unsignedBigInteger('run_id')->nullable()->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->unique(['backfill', 'record_id']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->dropIfExists('backfill_ledger');

        $schema->table('backfill_runs', function (Blueprint $table) {
            $table->dropColumn('tenant');
        });
    }
};
