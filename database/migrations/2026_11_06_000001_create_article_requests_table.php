<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A new article a user asked for from an order. `articles` only holds what
     * exists in Evoliz, so the request waits here until Evoliz has it.
     */
    public function up(): void
    {
        Schema::create('article_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained();
            $table->string('name', 60);
            $table->string('category', 30);
            $table->string('variant', 10);
            $table->decimal('unit_price_ht', 10, 2);
            $table->string('reference');
            $table->text('designation');
            $table->string('status', 10)->default('pending');
            $table->text('error')->nullable();
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('evoliz_jobs', function (Blueprint $table) {
            $table->foreignId('article_request_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evoliz_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('article_request_id');
        });

        Schema::dropIfExists('article_requests');
    }
};
