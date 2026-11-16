<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Evoliz requests with payload and response, kept for installations that already
        // have rows; never written, read by two lookups (App\Models\EvolizJob).
        Schema::create('evoliz_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->string('method', 8);
            $table->string('path');
            $table->jsonb('payload_json')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->jsonb('response_json')->nullable();
            $table->string('status');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // Set when the BL totals differ from the app's; the order still counts as sent.
            $table->text('evoliz_warning')->nullable()->after('evoliz_error');
        });

        // A failed push is still going to Evoliz (admin retry), so it keeps its stock.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW article_reservations AS
            SELECT order_lines.article_id, SUM(order_lines.quantity) AS reserved_qty
            FROM order_lines
            JOIN orders ON orders.id = order_lines.order_id
            WHERE orders.status IN ('pending', 'approved', 'failed')
              AND orders.deleted_at IS NULL
              AND order_lines.article_id IS NOT NULL
            GROUP BY order_lines.article_id
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW article_reservations AS
            SELECT order_lines.article_id, SUM(order_lines.quantity) AS reserved_qty
            FROM order_lines
            JOIN orders ON orders.id = order_lines.order_id
            WHERE orders.status IN ('pending', 'approved')
              AND orders.deleted_at IS NULL
              AND order_lines.article_id IS NOT NULL
            GROUP BY order_lines.article_id
            SQL);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('evoliz_warning');
        });

        Schema::dropIfExists('evoliz_jobs');
    }
};
