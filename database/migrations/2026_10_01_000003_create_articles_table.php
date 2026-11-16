<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evoliz_article_id')->unique();
            $table->string('reference');
            $table->text('designation');
            $table->decimal('unit_price_ht', 12, 2);
            $table->decimal('vat_rate', 5, 2);
            $table->unsignedBigInteger('sale_classification_id')->nullable();
            $table->boolean('is_stocked')->default(false);
            $table->decimal('stock_qty', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE INDEX articles_reference_trgm_index ON articles USING gin (reference gin_trgm_ops)');
        DB::statement('CREATE INDEX articles_designation_trgm_index ON articles USING gin (designation gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
