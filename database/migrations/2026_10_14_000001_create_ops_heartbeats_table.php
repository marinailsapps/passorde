<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Last sign of life of the background parts: "queue" and "scheduler"
        // (written every minute, read by ops:check) and "backup" (written by
        // deploy/backup.sh with psql, read by ops:daily-digest).
        Schema::create('ops_heartbeats', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->timestampTz('last_ok_at')->nullable();
            $table->timestampTz('last_failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_heartbeats');
    }
};
