<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Broken stock and gifts outside orders now adjust the Evoliz stock
        // (AdjustEvolizStockJob). evoliz_applied: Evoliz's stock currently includes
        // the deduction. A deleted entry is soft-deleted, so the job can add the
        // quantity back.
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->string('evoliz_status')->nullable()->after('user_id');
            $table->text('evoliz_error')->nullable()->after('evoliz_status');
            $table->decimal('evoliz_before', 12, 2)->nullable()->after('evoliz_error');
            $table->decimal('evoliz_after', 12, 2)->nullable()->after('evoliz_before');
            $table->boolean('evoliz_applied')->default(false)->after('evoliz_after');
            $table->softDeletes();
        });

        // evoliz_jobs also logs the stock requests of an adjustment (kind adjust_stock).
        Schema::table('evoliz_jobs', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->change();
            $table->foreignId('stock_adjustment_id')->nullable()->after('order_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evoliz_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_adjustment_id');
        });

        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['evoliz_status', 'evoliz_error', 'evoliz_before', 'evoliz_after', 'evoliz_applied']);
        });
    }
};
