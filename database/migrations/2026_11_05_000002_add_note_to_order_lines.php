<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The seller's comment on a line: reaches Evoliz added to the line's
     * designation (the catalogue designation stays in `designation`).
     */
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->text('note')->nullable()->after('designation');
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
