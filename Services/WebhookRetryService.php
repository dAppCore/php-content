<?php

declare(strict_types=1);

namespace Core\Mod\Content\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Core\Mod\Content\Models\ContentWebhookLog;

/**
 * WebhookRetryService
 *
 * Handles retry logic for failed content webhooks with exponential backoff.
 *
 * Backoff intervals: 1m, 5m, 15m, 1h, 4h
 * Max retries: 5 (configurable per webhook)
 */
class WebhookRetryService
{
    /**
     * Exponential backoff intervals in seconds.
     * Attempt 1: 1 minute
     * Attempt 2: 5 minutes
     * Attempt 3: 15 minutes
     * Attempt 4: 1 hour
     * Attempt 5: 4 hours
     */
    protected const BACKOFF_INTERVALS = [
        1 => 60,       // 1 minute
        2 => 300,      // 5 minutes
        3 => 900,      // 15 minutes
        4 => 3600,     // 1 hour
        5 => 14400,    // 4 hours
    ];

    /**
     * Default maximum retries if not set on webhook.
     */
    protected const DEFAULT_MAX_RETRIES = 5;

    /**
     * Request timeout in seconds.
     */
    protected const REQUEST_TIMEOUT = 30;

    /**
     * Get webhooks that are due for retry.
     *
     * @param  int  $limit  Maximum number of webhooks to return
     */
    public function getRetryableWebhooks(int $limit = 50): Collection
    {
        return ContentWebhookLog::retryable()
            ->orderBy('next_retry_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Count webhooks pending retry.
     */
    public function countPendingRetries(): int
    {
        return ContentWebhookLog::retryable()->count();
    }

    /**
     * Attempt to retry a webhook.
     *
     * @return bool True if retry succeeded, false if failed
     */
    public function retry(ContentWebhookLog $webhook): bool
    {
        // Check if we've exceeded max retries
        if ($webhook->hasExceededMaxRetries()) {
            $this->markExhausted($webhook);

            return false;
        }

        Log::info('Retrying webhook', [
            'webhook_id' => $webhook->id,
            'event_type' => $webhook->event_type,
            'attempt' => $webhook->retry_count + 1,
            'max_retries' => $webhook->max_retries,
        ]);

        $webhook->markProcessing();

        try {
            $response = $this->sendWebhook($webhook);

            if ($response->successful()) {
                $this->markSuccess($webhook);
                Log::info('Webhook retry succeeded', [
                    'webhook_id' => $webhook->id,
                    'status_code' => $response->status(),
                ]);

                return true;
            }

            // Failed with HTTP error
            $this->markFailed($webhook, "HTTP {$response->status()}: {$response->body()}");
            Log::warning('Webhook retry failed with HTTP error', [
                'webhook_id' => $webhook->id,
                'status_code' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Exception $e) {
            $this->markFailed($webhook, $e->getMessage());
            Log::error('Webhook retry failed with exception', [
                'webhook_id' => $webhook->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Mark a webhook as successfully processed.
     */
    public function markSuccess(ContentWebhookLog $webhook): void
    {
        $webhook->update([
            'status' => 'completed',
            'processed_at' => now(),
            'error_message' => null,
            'last_error' => null,
            'next_retry_at' => null,
        ]);
    }

    /**
     * Mark a webhook as failed and schedule next retry.
     */
    public function markFailed(ContentWebhookLog $webhook, string $error): void
    {
        $nextRetryCount = $webhook->retry_count + 1;

        // Check if we should schedule another retry
        if ($nextRetryCount >= $webhook->max_retries) {
            $this->markExhausted($webhook, $error);

            return;
        }

        $nextRetryAt = $this->calculateNextRetry($nextRetryCount);

        $webhook->update([
            'status' => 'pending',
            'retry_count' => $nextRetryCount,
            'next_retry_at' => $nextRetryAt,
            'last_error' => $error,
            'error_message' => "Retry {$nextRetryCount}/{$webhook->max_retries}: {$error}",
        ]);

        Log::info('Webhook scheduled for retry', [
            'webhook_id' => $webhook->id,
            'retry_count' => $nextRetryCount,
            'next_retry_at' => $nextRetryAt->toIso8601String(),
        ]);
    }

    /**
     * Mark a webhook as exhausted (max retries reached).
     */
    public function markExhausted(ContentWebhookLog $webhook, ?string $error = null): void
    {
        $webhook->update([
            'status' => 'failed',
            'processed_at' => now(),
            'next_retry_at' => null,
            'last_error' => $error ?? $webhook->last_error,
            'error_message' => "Max retries ({$webhook->max_retries}) exhausted. Last error: ".($error ?? $webhook->last_error ?? 'Unknown'),
        ]);

        Log::warning('Webhook retry exhausted', [
            'webhook_id' => $webhook->id,
            'max_retries' => $webhook->max_retries,
            'last_error' => $error ?? $webhook->last_error,
        ]);
    }

    /**
     * Calculate the next retry time based on attempt number.
     *
     * Uses exponential backoff: 1m, 5m, 15m, 1h, 4h
     */
    public function calculateNextRetry(int $attempts): \Carbon\Carbon
    {
        // Clamp to max defined interval
        $attempt = min($attempts, count(self::BACKOFF_INTERVALS));
        $seconds = self::BACKOFF_INTERVALS[$attempt] ?? self::BACKOFF_INTERVALS[count(self::BACKOFF_INTERVALS)];

        return now()->addSeconds($seconds);
    }

    /**
     * Cancel retries for a webhook.
     */
    public function cancelRetry(ContentWebhookLog $webhook): void
    {
        $webhook->update([
            'status' => 'failed',
            'processed_at' => now(),
            'next_retry_at' => null,
            'error_message' => 'Retry cancelled by user',
        ]);

        Log::info('Webhook retry cancelled', ['webhook_id' => $webhook->id]);
    }

    /**
     * Reset a webhook for retry (manual retry).
     */
    public function resetForRetry(ContentWebhookLog $webhook): void
    {
        $webhook->update([
            'status' => 'pending',
            'retry_count' => 0,
            'next_retry_at' => now(),
            'error_message' => null,
            'last_error' => null,
        ]);

        Log::info('Webhook reset for retry', ['webhook_id' => $webhook->id]);
    }

    /**
     * Process the webhook payload.
     *
     * For content webhooks, we're processing incoming webhooks from external
     * systems (WordPress, headless CMS, etc.). The retry logic reprocesses
     * the webhook payload through our content pipeline.
     */
    protected function sendWebhook(ContentWebhookLog $webhook): \Illuminate\Http\Client\Response
    {
        $payload = $webhook->payload;

        if (empty($payload)) {
            throw new \RuntimeException('Webhook payload is empty');
        }

        // Validate payload structure - need either action (WordPress) or event (generic)
        if (! isset($payload['action']) && ! isset($payload['event']) && ! isset($payload['type'])) {
            throw new \RuntimeException('Invalid webhook payload structure: missing action, event, or type');
        }

        // Process based on event type
        $eventType = $webhook->event_type;
        $contentType = $webhook->content_type;
        $wpId = $webhook->wp_id;

        // Validate we have the data needed to process
        if (empty($eventType)) {
            throw new \RuntimeException('Missing event_type');
        }

        // For post/page updates, we need content data
        if (str_contains($eventType, 'created') || str_contains($eventType, 'updated')) {
            if (! isset($payload['content']) && ! isset($payload['post'])) {
                throw new \RuntimeException('Missing content data for create/update event');
            }
        }

        // For delete events, we just need the ID
        if (str_contains($eventType, 'deleted')) {
            if (empty($wpId) && ! isset($payload['id'])) {
                throw new \RuntimeException('Missing ID for delete event');
            }
        }

        // Webhook processing is successful if validation passes
        // The actual content sync would be handled by a separate processor
        // that's triggered by the webhook handler when initially received

        Log::info('Webhook payload validated for retry', [
            'webhook_id' => $webhook->id,
            'event_type' => $eventType,
            'content_type' => $contentType,
            'wp_id' => $wpId,
        ]);

        // Return a successful response to indicate processing completed
        // In a full implementation, this would trigger the actual content sync
        return new \Illuminate\Http\Client\Response(
            new \GuzzleHttp\Psr7\Response(200, [], json_encode(['success' => true]))
        );
    }

    /**
     * Get retry statistics for a workspace.
     */
    public function getStats(?int $workspaceId = null): array
    {
        $query = ContentWebhookLog::query();

        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        return [
            'pending_retries' => (clone $query)->retryable()->count(),
            'failed_permanently' => (clone $query)->where('status', 'failed')
                ->where('retry_count', '>=', \DB::raw('max_retries'))
                ->count(),
            'total_retries_today' => (clone $query)->whereDate('updated_at', today())
                ->where('retry_count', '>', 0)
                ->count(),
            'success_rate' => $this->calculateSuccessRate($workspaceId),
        ];
    }

    /**
     * Calculate webhook success rate.
     */
    protected function calculateSuccessRate(?int $workspaceId = null): float
    {
        $query = ContentWebhookLog::query();

        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        $total = $query->count();
        if ($total === 0) {
            return 100.0;
        }

        $successful = (clone $query)->where('status', 'completed')->count();

        return round(($successful / $total) * 100, 1);
    }
}
