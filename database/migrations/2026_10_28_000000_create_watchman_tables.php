<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function getConnection(): ?string
    {
        return config('watchman.database.connection') ?: parent::getConnection();
    }

    private function t(string $name): string
    {
        return config('watchman.database.table_prefix', 'watchman_') . $name;
    }

    public function up(): void
    {
        Schema::create($this->t('generations'), function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('corpus', 191)->index();
            $table->boolean('active')->default(false)->index();
            $table->longText('meta');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create($this->t('entities'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('generation', 40);
            $table->unsignedInteger('idx');
            $table->string('source_list', 100);
            $table->string('entity_type', 32);
            $table->string('source_id', 191);
            $table->longText('payload');
            $table->unique(['generation', 'idx']);
            $table->index(['generation', 'source_list', 'entity_type']);
        });

        Schema::create($this->t('postings'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('generation', 40);
            $table->string('kind', 16);
            $table->string('pkey', 191);
            $table->unsignedInteger('idx');
            $table->string('source_list', 100);
            $table->string('entity_type', 32);
            $table->index(['generation', 'kind', 'pkey']);
        });

        Schema::create($this->t('identifiers'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('generation', 40);
            $table->string('kind', 16);
            $table->string('value', 191);
            $table->unsignedInteger('idx');
            $table->string('source_list', 100);
            $table->string('entity_type', 32);
            $table->index(['generation', 'kind', 'value']);
        });

        Schema::create($this->t('terms'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('generation', 40);
            $table->string('term', 191);
            $table->unsignedInteger('df');
            $table->index(['generation', 'term']);
        });

        // Ingested files (POST /v2/ingest/{fileType}) are persisted here so the
        // search index can be rebuilt from them (Watchman's ingested_entities table).
        Schema::create($this->t('ingested_entities'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('file_type', 100);
            $table->string('source_id', 191);
            $table->longText('entity');
            $table->timestamps();
            $table->index(['file_type', 'source_id']);
        });
    }

    public function down(): void
    {
        foreach (['ingested_entities', 'terms', 'identifiers', 'postings', 'entities', 'generations'] as $t) {
            Schema::dropIfExists($this->t($t));
        }
    }
};
