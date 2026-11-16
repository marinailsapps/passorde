<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Report data the app owns (Evoliz has no category, variant or threshold):
        // one row per article, filled by ArticleClassifier, the reference file or an admin.
        Schema::create('article_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('category')->nullable();
            $table->string('variant');
            // Per-article override of the category threshold, in units.
            $table->unsignedInteger('reorder_threshold')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('source');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['category', 'variant']);
            $table->index('needs_review');
        });

        Schema::create('category_thresholds', function (Blueprint $table) {
            $table->id();
            $table->string('category')->unique();
            $table->unsignedInteger('threshold');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Broken stock and gifts outside an order: recorded in the app only, never
        // sent to Evoliz (reconciled at the yearly inventory).
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->decimal('quantity', 10, 2);
            $table->date('happened_on');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index(['kind', 'happened_on']);
            $table->index(['article_id', 'happened_on']);
        });

        Schema::table('articles', function (Blueprint $table) {
            // Evoliz supplier.name and sale_classification.code (classifier input).
            $table->string('supplier_name')->nullable()->after('sale_classification_id');
            $table->string('sale_classification_code')->nullable()->after('supplier_name');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['supplier_name', 'sale_classification_code']);
        });
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('category_thresholds');
        Schema::dropIfExists('article_attributes');
    }
};
