<?php

declare(strict_types=1);

namespace Core\Mod\Content\Models;

use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentWebhookLog extends Model
{
    use HasFactory;

    protected static function newFactory(): \Core\Mod\Content\Database\Factories\ContentWebhookLogFactory
    {
        return \Core\Mod\Content\Database\Factories\ContentWebhookLogFactory::new();
    }

    protected $fillable = [
        'workspace_id',
        'endpoint_id',
        'event_type',
        'wp_id',
        'content_type',
        'payload',
        'request_headers',
        'status',
        'error_message',
        'source_ip',
        'signature_verified',
        'signature_failure_reason',
        'processed_at',
        'processing_duration_ms',
        'response_code',
        'response_body',
        'retry_count',
        'max_retries',
        'next_retry_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'request_headers' => 'array',
        'processed_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'retry_count' => 'integer',
        'max_retries' => 'integer',
        'signature_verified' => 'boolean',
        'processing_duration_ms' => 'integer',
        'response_code' => 'integer',
    ];

    /**
     * Get the workspace this log belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the webhook endpoint this log belongs to.
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(ContentWebhookEndpoint::class, 'endpoint_id');
    }

    /**
     * Mark as processing.
     */
    public function markProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    /**
     * Mark as completed.
     */
    public function markCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'processed_at' => now(),
            'error_message' => null,
        ]);
    }

    /**
     * Mark as failed.
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'processed_at' => now(),
            'error_message' => $error,
        ]);
    }

    /**
     * Record signature verification result.
     */
    public function recordSignatureVerification(bool $verified, string $reason): void
    {
        $this->update([
            'signature_verified' => $verified,
            'signature_failure_reason' => $verified ? null : $reason,
        ]);
    }

    /**
     * Record processing completion with duration.
     *
     * @param  int  $durationMs  Processing duration in milliseconds
     * @param  int|null  $responseCode  HTTP response code if applicable
     * @param  string|null  $responseBody  Response body if applicable
     */
    public function recordProcessingComplete(
        int $durationMs,
        ?int $responseCode = null,
        ?string $responseBody = null
    ): void {
        $this->update([
            'processing_duration_ms' => $durationMs,
            'response_code' => $responseCode,
            'response_body' => $responseBody ? substr($responseBody, 0, 10000) : null, // Limit size
        ]);
    }

    /**
     * Mark as completed with full details.
     */
    public function markCompletedWithDetails(
        int $durationMs,
        ?int $responseCode = null,
        ?string $responseBody = null
    ): void {
        $this->update([
            'status' => 'completed',
            'processed_at' => now(),
            'error_message' => null,
            'processing_duration_ms' => $durationMs,
            'response_code' => $responseCode,
            'response_body' => $responseBody ? substr($responseBody, 0, 10000) : null,
        ]);
    }

    /**
     * Mark as failed with full details.
     */
    public function markFailedWithDetails(
        string $error,
        int $durationMs,
        ?int $responseCode = null,
        ?string $responseBody = null
    ): void {
        $this->update([
            'status' => 'failed',
            'processed_at' => now(),
            'error_message' => $error,
            'processing_duration_ms' => $durationMs,
            'response_code' => $responseCode,
            'response_body' => $responseBody ? substr($responseBody, 0, 10000) : null,
        ]);
    }

    /**
     * Scope to filter by workspace.
     */
    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    /**
     * Scope to pending webhooks.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to failed webhooks.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope to webhooks with signature verification failures.
     */
    public function scopeSignatureFailed($query)
    {
        return $query->where('signature_verified', false);
    }

    /**
     * Scope to webhooks with successful signature verification.
     */
    public function scopeSignatureVerified($query)
    {
        return $query->where('signature_verified', true);
    }

    /**
     * Scope to webhooks that are ready for retry.
     *
     * Conditions:
     * - Status is 'pending' or 'failed'
     * - next_retry_at is in the past or null (for newly pending)
     * - retry_count is less than max_retries
     */
    public function scopeRetryable($query)
    {
        return $query->where(function ($q) {
            $q->where('status', 'pending')
                ->orWhere('status', 'failed');
        })
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->whereColumn('retry_count', '<', 'max_retries');
    }

    /**
     * Scope to webhooks scheduled for retry (not yet due).
     */
    public function scopeScheduledForRetry($query)
    {
        return $query->where('status', 'pending')
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '>', now());
    }

    /**
     * Scope to webhooks that have exhausted retries.
     */
    public function scopeExhausted($query)
    {
        return $query->where('status', 'failed')
            ->whereColumn('retry_count', '>=', 'max_retries');
    }

    /**
     * Get Flux badge colour for webhook status.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'yellow',
            'processing' => 'blue',
            'completed' => 'green',
            'failed' => 'red',
            default => 'zinc',
        };
    }

    /**
     * Get icon for webhook status.
     */
    public function getStatusIconAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'clock',
            'processing' => 'arrow-path',
            'completed' => 'check',
            'failed' => 'x-mark',
            default => 'question-mark-circle',
        };
    }

    /**
     * Get Flux badge colour for event type.
     */
    public function getEventColorAttribute(): string
    {
        return match (true) {
            str_contains($this->event_type, 'deleted') => 'red',
            str_contains($this->event_type, 'created') => 'green',
            str_contains($this->event_type, 'updated') => 'blue',
            str_contains($this->event_type, 'published') => 'green',
            default => 'zinc',
        };
    }

    /**
     * Check if this webhook has exceeded its maximum retry attempts.
     */
    public function hasExceededMaxRetries(): bool
    {
        return $this->retry_count >= $this->max_retries;
    }

    /**
     * Check if this webhook is scheduled for retry.
     */
    public function isScheduledForRetry(): bool
    {
        return $this->status === 'pending'
            && $this->next_retry_at !== null
            && $this->next_retry_at->isFuture();
    }

    /**
     * Check if this webhook can be retried.
     */
    public function canRetry(): bool
    {
        return in_array($this->status, ['pending', 'failed'])
            && ! $this->hasExceededMaxRetries();
    }

    /**
     * Get retry progress as a percentage.
     */
    public function getRetryProgressAttribute(): int
    {
        if ($this->max_retries === 0) {
            return 100;
        }

        return (int) round(($this->retry_count / $this->max_retries) * 100);
    }

    /**
     * Get human-readable retry status.
     */
    public function getRetryStatusAttribute(): string
    {
        if ($this->status === 'completed') {
            return 'Completed';
        }

        if ($this->hasExceededMaxRetries()) {
            return 'Exhausted';
        }

        if ($this->isScheduledForRetry()) {
            return "Retry #{$this->retry_count} scheduled for ".$this->next_retry_at->diffForHumans();
        }

        if ($this->retry_count > 0) {
            return "Failed after {$this->retry_count} retries";
        }

        return 'Pending';
    }
}
