<?php

declare(strict_types=1);

namespace Core\Mod\Content\Models;

use Mod\Agentic\Models\Prompt;
use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContentTask extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    protected $fillable = [
        'workspace_id',
        'prompt_id',
        'status',
        'priority',
        'input_data',
        'output',
        'metadata',
        'target_type',
        'target_id',
        'scheduled_for',
        'started_at',
        'completed_at',
        'error_message',
    ];

    protected $casts = [
        'input_data' => 'array',
        'metadata' => 'array',
        'scheduled_for' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the workspace this task belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the prompt used for this task.
     */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }

    /**
     * Get the target model (polymorphic).
     */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Mark the task as processing.
     */
    public function markProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'started_at' => now(),
        ]);
    }

    /**
     * Mark the task as completed with output.
     */
    public function markCompleted(string $output, array $metadata = []): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'output' => $output,
            'metadata' => array_merge($this->metadata ?? [], $metadata),
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark the task as failed with error message.
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $error,
            'completed_at' => now(),
        ]);
    }

    /**
     * Scope to pending tasks.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to scheduled tasks ready to process.
     */
    public function scopeReadyToProcess($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->where('scheduled_for', '<=', now());
    }

    /**
     * Scope by priority.
     */
    public function scopePriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Calculate processing duration in seconds.
     */
    public function getDurationAttribute(): ?int
    {
        if (! $this->started_at || ! $this->completed_at) {
            return null;
        }

        return $this->completed_at->diffInSeconds($this->started_at);
    }

    /**
     * Check if the task is in a terminal state.
     */
    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED]);
    }
}
