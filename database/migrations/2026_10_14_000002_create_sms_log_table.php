<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per SMS attempt to the client of a sent order (SendOrderSmsJob).
        Schema::create('sms_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            // Normalised +33 mobile; null when the client has none (status "skipped").
            $table->string('phone', 20)->nullable();
            $table->text('text');
            $table->unsignedBigInteger('ovh_job_id')->nullable();
            $table->string('status');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'id']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('clients', function (Blueprint $table) {
            // Evoliz's "mobile" field, kept apart from phone so the SMS reaches a mobile.
            $table->string('mobile')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('mobile');
        });

        Schema::dropIfExists('sms_log');
    }
};
