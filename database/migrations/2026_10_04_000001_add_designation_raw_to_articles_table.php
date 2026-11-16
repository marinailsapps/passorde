<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The designation exactly as Evoliz returns it (HTML entities kept), used
     * for free lines so they read like the operator's own lines.
     */
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->text('designation_raw')->nullable()->after('designation');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('designation_raw');
        });
    }
};
