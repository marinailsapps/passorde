<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Evoliz users seen on delivery notes, mapped by an admin to app users
        // ("Vendeurs Evoliz"). Unmapped ones show as "Evoliz".
        Schema::create('evoliz_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evoliz_user_id')->unique();
            $table->string('label')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Read-only mirror of the Evoliz delivery notes (DeliverySync): the
        // sales history and the sales report. A BL that disappears from Evoliz
        // in a re-synced window is soft-deleted.
        Schema::create('evoliz_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evoliz_delivery_id')->unique();
            $table->string('document_number');
            $table->string('external_document_number')->nullable();
            $table->unsignedBigInteger('evoliz_client_id')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            // The app order this BL was created from (external_document_number = order number).
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('evoliz_user_id')->nullable();
            $table->string('client_name')->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->string('status');
            $table->boolean('invoiced')->default(false);
            $table->date('documentdate');
            $table->decimal('total_ht', 12, 2);
            $table->decimal('total_vat', 12, 2);
            $table->decimal('total_ttc', 12, 2);
            $table->decimal('total_quantity', 10, 2);
            $table->text('comment')->nullable();
            // Hash of the lines as Evoliz returned them: lines are rewritten only when it changes.
            $table->string('lines_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('documentdate');
            $table->index(['client_id', 'documentdate']);
            $table->index('updated_at');
        });

        Schema::create('evoliz_delivery_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('evoliz_deliveries')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedBigInteger('evoliz_article_id')->nullable();
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->nullable();
            $table->text('designation');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price_ht', 12, 2);
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('total_ht', 12, 2);
            $table->boolean('is_free');
            $table->timestamps();

            $table->index('delivery_id');
            $table->index('article_id');
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evoliz_delivery_lines');
        Schema::dropIfExists('evoliz_deliveries');
        Schema::dropIfExists('evoliz_users');
    }
};
