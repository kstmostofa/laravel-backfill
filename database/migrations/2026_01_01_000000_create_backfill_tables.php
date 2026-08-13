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

        $schema->create('backfill_runs', function (Blueprint $table) {
            $table->id();
            $table->string('backfill')->index();
            $table->string('backfill_class');
            $table->string('status')->index();

            // String so integer, UUID and ULID keys all round-trip losslessly.
            $table->string('cursor')->nullable();
            $table->string('key_name')->default('id');

            $table->unsignedBigInteger('total_estimate')->nullable();
            $table->unsignedBigInteger('processed_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);
            $table->unsignedBigInteger('skipped_count')->default(0);
            $table->unsignedInteger('batch_count')->default(0);

            $table->unsignedInteger('batch_size');
            $table->unsignedInteger('sleep_ms')->default(0);
            $table->boolean('dry_run')->default(false);

            $table->string('started_by')->nullable();
            $table->timestamp('heartbeat_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
        });

        $schema->create('backfill_run_errors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id')->index();
            $table->string('record_id')->nullable()->index();
            $table->string('exception_class');
            $table->text('message');
            $table->longText('trace')->nullable();
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        // Acquiring a run lock is an INSERT against this unique index. A row
        // table rather than a partial index keeps the guarantee identical on
        // MySQL, PostgreSQL and SQLite.
        $schema->create('backfill_locks', function (Blueprint $table) {
            $table->id();
            $table->string('backfill')->unique();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->string('owner')->nullable();
            $table->timestamp('acquired_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->dropIfExists('backfill_locks');
        $schema->dropIfExists('backfill_run_errors');
        $schema->dropIfExists('backfill_runs');
    }
};
