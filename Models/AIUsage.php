<?php

declare(strict_types=1);

namespace Core\Content\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Core\Mod\Tenant\Models\Workspace;

/**
 * AIUsage Model
 *
 * Tracks AI API usage for cost tracking, billing, and analytics.
 * Supports both workspace-level and system-level usage tracking.
 */
class AIUsage extends Model
{
    use HasFactory;

    protected static function newFactory(): \Core\Content\Database\Factories\AIUsageFactory
    {
        return \Core\Content\Database\Factories\AIUsageFactory::new();
    }

    protected $table = 'ai_usage';

    public const PROVIDER_GEMINI = 'gemini';

    public const PROVIDER_CLAUDE = 'claude';

    public const PROVIDER_OPENAI = 'openai';

    public const PURPOSE_DRAFT = 'draft';

    public const PURPOSE_REFINE = 'refine';

    public const PURPOSE_SOCIAL = 'social';

    public const PURPOSE_IMAGE = 'image';

    public const PURPOSE_CHAT = 'chat';

    protected $fillable = [
        'workspace_id',
        'provider',
        'model',
        'purpose',
        'input_tokens',
        'output_tokens',
        'cost_estimate',
        'brief_id',
        'target_type',
        'target_id',
        'duration_ms',
        'metadata',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cost_estimate' => 'decimal:6',
        'duration_ms' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * Model pricing per 1M tokens.
     */
    protected static array $pricing = [
        'claude-sonnet-4-20250514' => ['input' => 3.00, 'output' => 15.00],
        'claude-opus-4-20250514' => ['input' => 15.00, 'output' => 75.00],
        'gemini-2.0-flash' => ['input' => 0.075, 'output' => 0.30],
        'gemini-2.0-flash-thinking' => ['input' => 0.70, 'output' => 3.50],
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
    ];

    /**
     * Get the workspace this usage belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the brief this usage is associated with.
     */
    public function brief(): BelongsTo
    {
        return $this->belongsTo(ContentBrief::class, 'brief_id');
    }

    /**
     * Get the target model (polymorphic).
     */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get total tokens used.
     */
    public function getTotalTokensAttribute(): int
    {
        return $this->input_tokens + $this->output_tokens;
    }

    /**
     * Calculate cost estimate based on model pricing.
     */
    public static function calculateCost(string $model, int $inputTokens, int $outputTokens): float
    {
        $pricing = static::$pricing[$model] ?? ['input' => 0, 'output' => 0];

        return ($inputTokens * $pricing['input'] / 1_000_000) +
               ($outputTokens * $pricing['output'] / 1_000_000);
    }

    /**
     * Create a usage record from an AgenticResponse.
     */
    public static function fromResponse(
        \Mod\Agentic\Services\AgenticResponse $response,
        string $purpose,
        ?int $workspaceId = null,
        ?int $briefId = null,
        ?Model $target = null
    ): self {
        $provider = str_contains($response->model, 'gemini') ? self::PROVIDER_GEMINI :
                   (str_contains($response->model, 'claude') ? self::PROVIDER_CLAUDE : self::PROVIDER_OPENAI);

        return self::create([
            'workspace_id' => $workspaceId,
            'provider' => $provider,
            'model' => $response->model,
            'purpose' => $purpose,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cost_estimate' => $response->estimateCost(),
            'brief_id' => $briefId,
            'target_type' => $target ? get_class($target) : null,
            'target_id' => $target?->id,
            'duration_ms' => $response->durationMs,
            'metadata' => [
                'stop_reason' => $response->stopReason,
            ],
        ]);
    }

    /**
     * Scope by provider.
     */
    public function scopeProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /**
     * Scope by purpose.
     */
    public function scopePurpose($query, string $purpose)
    {
        return $query->where('purpose', $purpose);
    }

    /**
     * Scope to a date range.
     */
    public function scopeDateRange($query, $start, $end)
    {
        return $query->whereBetween('created_at', [$start, $end]);
    }

    /**
     * Scope for current month.
     */
    public function scopeThisMonth($query)
    {
        return $query->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);
    }

    /**
     * Get aggregated stats for a workspace.
     */
    public static function statsForWorkspace(?int $workspaceId, ?string $period = 'month'): array
    {
        $query = static::query();

        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        match ($period) {
            'day' => $query->whereDate('created_at', today()),
            'week' => $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]),
            'month' => $query->thisMonth(),
            'year' => $query->whereYear('created_at', now()->year),
            default => null,
        };

        return [
            'total_requests' => $query->count(),
            'total_input_tokens' => $query->sum('input_tokens'),
            'total_output_tokens' => $query->sum('output_tokens'),
            'total_cost' => (float) $query->sum('cost_estimate'),
            'by_provider' => $query->clone()
                ->selectRaw('provider, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(cost_estimate) as cost')
                ->groupBy('provider')
                ->get()
                ->keyBy('provider')
                ->toArray(),
            'by_purpose' => $query->clone()
                ->selectRaw('purpose, COUNT(*) as count, SUM(cost_estimate) as cost')
                ->groupBy('purpose')
                ->get()
                ->keyBy('purpose')
                ->toArray(),
        ];
    }
}
