<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add retry tracking fields to content_webhook_logs table.
     *
     * Supports exponential backoff retry logic:
     * - retry_count tracks attempts
     * - next_retry_at schedules retries
     * - max_retries sets the limit (default 5)
     * - last_error preserves most recent failure reason
     */
    public function up(): void
    {
        Schema::table('content_webhook_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('retry_count')->default(0)->after('error_message');
            $table->unsignedTinyInteger('max_retries')->default(5)->after('retry_count');
            $table->timestamp('next_retry_at')->nullable()->after('max_retries');
            $table->text('last_error')->nullable()->after('next_retry_at');

            // Index for efficient querying of retryable webhooks
            $table->index(['status', 'next_retry_at', 'retry_count'], 'webhook_retry_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::table('content_webhook_logs', function (Blueprint $table) {
            $table->dropIndex('webhook_retry_queue_idx');
            $table->dropColumn(['retry_count', 'max_retries', 'next_retry_at', 'last_error']);
        });
    }
};
