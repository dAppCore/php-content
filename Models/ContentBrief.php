<?php

declare(strict_types=1);

namespace Core\Mod\Content\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Core\Mod\Content\Enums\BriefContentType;
use Core\Mod\Tenant\Models\Workspace;

/**
 * ContentBrief Model
 *
 * Represents a content creation brief that drives AI-powered content generation.
 * Briefs can be system-level (for marketing content) or workspace-specific.
 *
 * Workflow: pending → queued → generating → review → published
 */
class ContentBrief extends Model
{
    use HasFactory;

    protected static function newFactory(): \Core\Mod\Content\Database\Factories\ContentBriefFactory
    {
        return \Core\Mod\Content\Database\Factories\ContentBriefFactory::new();
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_REVIEW = 'review';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    public const DIFFICULTY_BEGINNER = 'beginner';

    public const DIFFICULTY_INTERMEDIATE = 'intermediate';

    public const DIFFICULTY_ADVANCED = 'advanced';

    protected $fillable = [
        'workspace_id',
        'service',
        'content_type',
        'title',
        'slug',
        'description',
        'keywords',
        'category',
        'difficulty',
        'target_word_count',
        'prompt_variables',
        'status',
        'priority',
        'scheduled_for',
        'draft_output',
        'refined_output',
        'final_content',
        'metadata',
        'generation_log',
        'content_item_id',
        'published_url',
        'generated_at',
        'refined_at',
        'published_at',
        'error_message',
    ];

    protected $casts = [
        'content_type' => BriefContentType::class,
        'keywords' => 'array',
        'prompt_variables' => 'array',
        'metadata' => 'array',
        'generation_log' => 'array',
        'scheduled_for' => 'datetime',
        'generated_at' => 'datetime',
        'refined_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    /**
     * Get the workspace this brief belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the published ContentItem if any.
     */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /**
     * Get AI usage records for this brief.
     */
    public function aiUsage(): HasMany
    {
        return $this->hasMany(AIUsage::class, 'brief_id');
    }

    /**
     * Mark the brief as queued for generation.
     */
    public function markQueued(): void
    {
        $this->update(['status' => self::STATUS_QUEUED]);
    }

    /**
     * Mark the brief as currently generating.
     */
    public function markGenerating(): void
    {
        $this->update(['status' => self::STATUS_GENERATING]);
    }

    /**
     * Mark the brief as ready for review with draft output.
     */
    public function markDraftComplete(string $draftOutput, array $log = []): void
    {
        $this->update([
            'draft_output' => $draftOutput,
            'generated_at' => now(),
            'generation_log' => array_merge($this->generation_log ?? [], $log),
        ]);
    }

    /**
     * Mark the brief as refined with Claude output.
     */
    public function markRefined(string $refinedOutput, array $log = []): void
    {
        $this->update([
            'refined_output' => $refinedOutput,
            'refined_at' => now(),
            'status' => self::STATUS_REVIEW,
            'generation_log' => array_merge($this->generation_log ?? [], $log),
        ]);
    }

    /**
     * Mark the brief as published.
     */
    public function markPublished(string $finalContent, ?string $publishedUrl = null, ?int $contentItemId = null): void
    {
        $this->update([
            'final_content' => $finalContent,
            'published_url' => $publishedUrl,
            'content_item_id' => $contentItemId,
            'published_at' => now(),
            'status' => self::STATUS_PUBLISHED,
        ]);
    }

    /**
     * Mark the brief as failed.
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $error,
        ]);
    }

    /**
     * Scope to pending briefs.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to queued briefs ready for processing.
     */
    public function scopeReadyToProcess($query)
    {
        return $query->where('status', self::STATUS_QUEUED)
            ->where(function ($q) {
                $q->whereNull('scheduled_for')
                    ->orWhere('scheduled_for', '<=', now());
            })
            ->orderByDesc('priority')
            ->orderBy('created_at');
    }

    /**
     * Scope to briefs needing review.
     */
    public function scopeNeedsReview($query)
    {
        return $query->where('status', self::STATUS_REVIEW);
    }

    /**
     * Scope by service.
     */
    public function scopeForService($query, string $service)
    {
        return $query->where('service', $service);
    }

    /**
     * Get the total estimated cost for this brief.
     */
    public function getTotalCostAttribute(): float
    {
        return $this->aiUsage()->sum('cost_estimate');
    }

    /**
     * Get the best available content (refined > draft).
     */
    public function getBestContentAttribute(): ?string
    {
        return $this->final_content ?? $this->refined_output ?? $this->draft_output;
    }

    /**
     * Check if the brief has been generated.
     */
    public function isGenerated(): bool
    {
        return $this->draft_output !== null;
    }

    /**
     * Check if the brief has been refined.
     */
    public function isRefined(): bool
    {
        return $this->refined_output !== null;
    }

    /**
     * Check if the brief is in a terminal state.
     */
    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_PUBLISHED, self::STATUS_FAILED]);
    }

    /**
     * Build the prompt context for AI generation.
     */
    public function buildPromptContext(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'keywords' => $this->keywords ?? [],
            'category' => $this->category,
            'difficulty' => $this->difficulty,
            'target_word_count' => $this->target_word_count,
            'content_type' => $this->content_type instanceof BriefContentType
                ? $this->content_type->value
                : $this->content_type,
            'service' => $this->service,
            ...$this->prompt_variables ?? [],
        ];
    }

    /**
     * Get the content type enum instance.
     */
    public function getContentTypeEnum(): ?BriefContentType
    {
        return $this->content_type instanceof BriefContentType
            ? $this->content_type
            : BriefContentType::tryFromString($this->content_type);
    }

    /**
     * Get the recommended timeout for AI generation based on content type.
     */
    public function getRecommendedTimeout(): int
    {
        $enum = $this->getContentTypeEnum();

        return $enum?->recommendedTimeout() ?? 180;
    }

    /**
     * Get Flux badge colour for content type.
     */
    public function getContentTypeColorAttribute(): string
    {
        $enum = $this->getContentTypeEnum();

        return $enum?->color() ?? 'zinc';
    }

    /**
     * Get human-readable content type label.
     */
    public function getContentTypeLabelAttribute(): string
    {
        $enum = $this->getContentTypeEnum();

        return $enum?->label() ?? ucfirst(str_replace('_', ' ', $this->content_type ?? 'unknown'));
    }
}
