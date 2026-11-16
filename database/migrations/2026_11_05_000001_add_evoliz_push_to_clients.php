<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A client saved without an order goes to Evoliz on its own (PushClientToEvolizJob).
        // evoliz_error: why it did not, for the admin's "Envoyer à Evoliz" and the digest.
        Schema::table('clients', function (Blueprint $table) {
            $table->text('evoliz_error')->nullable()->after('evoliz_code');
        });

        // evoliz_jobs also logs those requests, with no order.
        Schema::table('evoliz_jobs', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('stock_adjustment_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evoliz_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('evoliz_error');
        });
    }
};
