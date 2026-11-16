<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * File mode: an exported order plays the role of a sent one (its reservation
 * ends, it counts as a sale). Always null in Evoliz mode, so the view below
 * returns the same rows there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('exported_at')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users');
        });

        DB::statement("CREATE INDEX orders_to_export_index ON orders (approved_at) WHERE status = 'approved' AND exported_at IS NULL");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW article_reservations AS
            SELECT order_lines.article_id, SUM(order_lines.quantity) AS reserved_qty
            FROM order_lines
            JOIN orders ON orders.id = order_lines.order_id
            WHERE orders.status IN ('pending', 'approved', 'failed')
              AND orders.exported_at IS NULL
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
            WHERE orders.status IN ('pending', 'approved', 'failed')
              AND orders.deleted_at IS NULL
              AND order_lines.article_id IS NOT NULL
            GROUP BY order_lines.article_id
            SQL);

        DB::statement('DROP INDEX orders_to_export_index');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exported_by');
            $table->dropColumn('exported_at');
        });
    }
};
