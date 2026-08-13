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
        Schema::connection($this->connection)->create('backfill_run_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id')->index();
            $table->string('from_id')->nullable();
            $table->string('to_id')->nullable();
            $table->unsignedInteger('count')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('backfill_run_batches');
    }
};
