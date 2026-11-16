<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per year; the upsert in OrderNumberAllocator locks it.
        Schema::create('order_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('pending');
            $table->foreignId('paytype_id')->nullable()->constrained()->nullOnDelete();
            $table->text('comment')->nullable();
            $table->decimal('total_quantity', 10, 2)->default(0);
            $table->decimal('total_ht', 12, 2)->default(0);
            $table->decimal('total_vat', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            $table->text('rejected_reason')->nullable();
            $table->unsignedBigInteger('evoliz_delivery_id')->nullable()->unique();
            $table->string('evoliz_delivery_number')->nullable();
            $table->text('evoliz_error')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
            $table->index(['client_id', 'created_at']);
            $table->index('status');
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('article_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('designation');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price_ht', 12, 2);
            $table->decimal('vat_rate', 5, 2);
            $table->boolean('is_free')->default(false);
            $table->decimal('total_ht', 12, 2);
            $table->timestamps();

            $table->index(['order_id', 'position']);
            $table->index('article_id');
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->jsonb('meta_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });

        // Quantity held by orders that are not yet in Evoliz (pending or approved).
        // Deleted orders and lines without an article do not reserve anything.
        DB::statement(<<<'SQL'
            CREATE VIEW article_reservations AS
            SELECT order_lines.article_id, SUM(order_lines.quantity) AS reserved_qty
            FROM order_lines
            JOIN orders ON orders.id = order_lines.order_id
            WHERE orders.status IN ('pending', 'approved')
              AND orders.deleted_at IS NULL
              AND order_lines.article_id IS NOT NULL
            GROUP BY order_lines.article_id
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS article_reservations');
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_counters');
    }
};
