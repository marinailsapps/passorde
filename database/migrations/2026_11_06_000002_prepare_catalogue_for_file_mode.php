<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * File mode: articles, clients and paytypes exist with no Evoliz id. The unique
 * indexes on the ids stay (PostgreSQL allows several NULLs). reference and code
 * are the import's keys. Evoliz itself may hold duplicates, so neither index may
 * match a row of an Evoliz instance: articles always have an id there, and for
 * clients the index covers imported rows only, because OrderPusher::linkClient
 * keeps the code on a typed client before it has an id (and after a failed create).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE articles ALTER COLUMN evoliz_article_id DROP NOT NULL');
        DB::statement('ALTER TABLE paytypes ALTER COLUMN evoliz_paytype_id DROP NOT NULL');
        DB::statement('CREATE UNIQUE INDEX articles_file_reference_unique ON articles (reference) WHERE evoliz_article_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX clients_file_code_unique ON clients (evoliz_code) WHERE evoliz_client_id IS NULL AND evoliz_code IS NOT NULL AND source = \'import\'');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX clients_file_code_unique');
        DB::statement('DROP INDEX articles_file_reference_unique');
        DB::statement('ALTER TABLE paytypes ALTER COLUMN evoliz_paytype_id SET NOT NULL');
        DB::statement('ALTER TABLE articles ALTER COLUMN evoliz_article_id SET NOT NULL');
    }
};
