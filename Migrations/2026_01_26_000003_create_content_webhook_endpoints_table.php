<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('content_webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name');
            $table->text('secret')->nullable();
            $table->json('allowed_types')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('last_received_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'is_enabled']);
            $table->index('uuid');
        });

        // Add endpoint_id to webhook logs
        Schema::table('content_webhook_logs', function (Blueprint $table) {
            $table->foreignId('endpoint_id')
                ->nullable()
                ->after('workspace_id')
                ->constrained('content_webhook_endpoints')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('content_webhook_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('endpoint_id');
        });

        Schema::dropIfExists('content_webhook_endpoints');
    }
};
