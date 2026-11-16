<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Approved while Evoliz was off or unavailable (App\Domain\Orders\ApprovalService::settleLocally):
     * such an order is never pushed to Evoliz on its own, and never once exported (docs/RUNBOOK.md
     * "EVOLIZ_ENABLED and the Evoliz settings"). NULL for every order approved in Evoliz mode, so for all existing rows.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('standalone_at')->nullable()->after('exported_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('standalone_at');
        });
    }
};
