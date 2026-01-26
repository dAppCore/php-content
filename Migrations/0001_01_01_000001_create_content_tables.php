<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Content module tables - WordPress sync, content items, AI prompts.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        // 1. Prompts
        Schema::create('prompts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('category');
            $table->text('description')->nullable();
            $table->longText('system_prompt');
            $table->longText('user_template');
            $table->json('variables')->nullable();
            $table->string('model')->default('claude');
            $table->json('model_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('category');
            $table->index('model');
            $table->index('is_active');
        });

        // 2. Prompt Versions
        Schema::create('prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_id')->constrained('prompts')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->longText('system_prompt');
            $table->longText('user_template');
            $table->json('variables')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['prompt_id', 'version']);
        });

        // 3. Content Items
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('wp_id')->nullable();
            $table->string('wp_guid', 512)->nullable();
            $table->enum('type', ['post', 'page', 'attachment'])->default('post');
            $table->string('content_type')->default('wordpress');
            $table->string('status', 20)->default('draft');
            $table->timestamp('publish_at')->nullable();
            $table->string('slug', 200);
            $table->string('title', 500);
            $table->text('excerpt')->nullable();
            $table->longText('content_html_original')->nullable();
            $table->longText('content_html_clean')->nullable();
            $table->json('content_json')->nullable();
            $table->longText('content_html')->nullable();
            $table->longText('content_markdown')->nullable();
            $table->json('editor_state')->nullable();
            $table->timestamp('wp_created_at')->nullable();
            $table->timestamp('wp_modified_at')->nullable();
            $table->unsignedBigInteger('featured_media_id')->nullable();
            $table->json('seo_meta')->nullable();
            $table->string('sync_status')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->unsignedInteger('revision_count')->default(0);
            $table->json('cdn_urls')->nullable();
            $table->timestamp('cdn_purged_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'wp_id', 'type']);
            $table->index(['workspace_id', 'slug', 'type']);
            $table->index(['workspace_id', 'status', 'type']);
            $table->index(['workspace_id', 'sync_status']);
            $table->index(['workspace_id', 'status', 'content_type']);
            $table->index('author_id');
            $table->index('wp_id');
            $table->index('slug');
            $table->index('content_type');
            $table->index(['status', 'publish_at']);
        });

        // 4. Content Taxonomies
        Schema::create('content_taxonomies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->unsignedBigInteger('wp_id')->nullable();
            $table->enum('type', ['category', 'tag'])->default('category');
            $table->string('name', 200);
            $table->string('slug', 200);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('parent_wp_id')->nullable();
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'wp_id', 'type']);
            $table->index(['workspace_id', 'type']);
            $table->index(['workspace_id', 'slug']);
        });

        // 5. Content Item Taxonomy
        Schema::create('content_item_taxonomy', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $table->foreignId('content_taxonomy_id')->constrained('content_taxonomies')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['content_item_id', 'content_taxonomy_id'], 'content_taxonomy_unique');
        });

        // 6. Content Media
        Schema::create('content_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->unsignedBigInteger('wp_id');
            $table->string('title', 500)->nullable();
            $table->string('filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('source_url', 1000);
            $table->string('cdn_url', 1000)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text', 500)->nullable();
            $table->text('caption')->nullable();
            $table->json('sizes')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'wp_id']);
            $table->index(['workspace_id', 'mime_type']);
        });

        // 7. Content Revisions
        Schema::create('content_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('title', 500);
            $table->text('excerpt')->nullable();
            $table->longText('content_html')->nullable();
            $table->longText('content_markdown')->nullable();
            $table->json('content_json')->nullable();
            $table->json('editor_state')->nullable();
            $table->json('seo_meta')->nullable();
            $table->string('status', 20);
            $table->string('change_type', 50)->default('edit');
            $table->text('change_summary')->nullable();
            $table->unsignedInteger('word_count')->nullable();
            $table->unsignedInteger('char_count')->nullable();
            $table->timestamps();

            $table->index(['content_item_id', 'revision_number']);
            $table->index(['content_item_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // 8. Content Webhook Logs
        Schema::create('content_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            $table->string('event_type', 50);
            $table->unsignedBigInteger('wp_id')->nullable();
            $table->string('content_type', 20)->nullable();
            $table->json('payload')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        // 9. Content Tasks
        Schema::create('content_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('prompt_id')->constrained('prompts')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->string('priority')->default('normal');
            $table->json('input_data');
            $table->longText('output')->nullable();
            $table->json('metadata')->nullable();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('priority');
            $table->index('scheduled_for');
            $table->index(['target_type', 'target_id']);
        });

        // 10. Content Briefs
        Schema::create('content_briefs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('content_item_id')->nullable()->constrained('content_items')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('type', 32)->default('article');
            $table->json('target_audience')->nullable();
            $table->json('keywords')->nullable();
            $table->json('outline')->nullable();
            $table->json('tone_style')->nullable();
            $table->json('references')->nullable();
            $table->unsignedInteger('target_word_count')->nullable();
            $table->date('target_publish_date')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'status']);
            $table->index('scheduled_for');
        });

        // 11. AI Usage
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('model');
            $table->string('provider')->default('anthropic');
            $table->string('feature');
            $table->unsignedInteger('input_tokens');
            $table->unsignedInteger('output_tokens');
            $table->decimal('cost', 10, 6)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['model', 'created_at']);
            $table->index(['feature', 'created_at']);
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('content_briefs');
        Schema::dropIfExists('content_tasks');
        Schema::dropIfExists('content_webhook_logs');
        Schema::dropIfExists('content_revisions');
        Schema::dropIfExists('content_media');
        Schema::dropIfExists('content_item_taxonomy');
        Schema::dropIfExists('content_taxonomies');
        Schema::dropIfExists('content_items');
        Schema::dropIfExists('prompt_versions');
        Schema::dropIfExists('prompts');
        Schema::enableForeignKeyConstraints();
    }
};
