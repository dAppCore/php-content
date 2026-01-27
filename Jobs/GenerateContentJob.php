<?php

declare(strict_types=1);

namespace Core\Mod\Content\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Core\Mod\Content\Models\ContentBrief;
use Core\Mod\Content\Services\AIGatewayService;

/**
 * GenerateContentJob
 *
 * Handles async AI content generation for briefs.
 * Supports draft, refine, and full pipeline modes.
 */
class GenerateContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout;

    /**
     * Calculate the number of seconds to wait before retrying.
     */
    public function backoff(): array
    {
        return config('content.generation.backoff', [30, 60, 120]);
    }

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ContentBrief $brief,
        public string $mode = 'full', // draft, refine, full
        public ?array $context = null,
    ) {
        $this->onQueue('content-generation');

        // Set configurable retries and timeout
        $this->tries = config('content.generation.max_retries', 3);
        $this->timeout = $this->resolveTimeout();
    }

    /**
     * Resolve the timeout based on content type or config.
     */
    protected function resolveTimeout(): int
    {
        // Try to get content-type-specific timeout
        $contentType = $this->brief->content_type;
        $contentTypeKey = is_string($contentType) ? $contentType : $contentType?->value;

        if ($contentTypeKey) {
            $configuredTimeout = config("content.generation.timeouts.{$contentTypeKey}");
            if ($configuredTimeout) {
                return (int) $configuredTimeout;
            }
        }

        // Fall back to brief's recommended timeout if using enum
        if (method_exists($this->brief, 'getRecommendedTimeout')) {
            return $this->brief->getRecommendedTimeout();
        }

        // Final fallback to default config
        return (int) config('content.generation.default_timeout', 300);
    }

    /**
     * Execute the job.
     */
    public function handle(AIGatewayService $gateway): void
    {
        Log::info('Starting content generation', [
            'brief_id' => $this->brief->id,
            'mode' => $this->mode,
            'title' => $this->brief->title,
        ]);

        try {
            match ($this->mode) {
                'draft' => $this->generateDraft($gateway),
                'refine' => $this->refineDraft($gateway),
                'full' => $this->generateFull($gateway),
                default => throw new \InvalidArgumentException("Invalid mode: {$this->mode}"),
            };

            Log::info('Content generation completed', [
                'brief_id' => $this->brief->id,
                'mode' => $this->mode,
                'status' => $this->brief->fresh()->status,
            ]);
        } catch (\Exception $e) {
            Log::error('Content generation failed', [
                'brief_id' => $this->brief->id,
                'mode' => $this->mode,
                'error' => $e->getMessage(),
            ]);

            $this->brief->markFailed($e->getMessage());

            throw $e;
        }
    }

    /**
     * Generate draft using Gemini.
     */
    protected function generateDraft(AIGatewayService $gateway): void
    {
        if ($this->brief->isGenerated()) {
            Log::info('Draft already exists, skipping', ['brief_id' => $this->brief->id]);

            return;
        }

        $response = $gateway->generateDraft($this->brief, $this->context);

        $this->brief->markDraftComplete($response->content, [
            'draft' => [
                'model' => $response->model,
                'tokens' => $response->totalTokens(),
                'cost' => $response->estimateCost(),
                'duration_ms' => $response->durationMs,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Refine draft using Claude.
     */
    protected function refineDraft(AIGatewayService $gateway): void
    {
        if (! $this->brief->isGenerated()) {
            throw new \RuntimeException('No draft to refine. Generate draft first.');
        }

        if ($this->brief->isRefined()) {
            Log::info('Draft already refined, skipping', ['brief_id' => $this->brief->id]);

            return;
        }

        $response = $gateway->refineDraft(
            $this->brief,
            $this->brief->draft_output,
            $this->context
        );

        $this->brief->markRefined($response->content, [
            'refine' => [
                'model' => $response->model,
                'tokens' => $response->totalTokens(),
                'cost' => $response->estimateCost(),
                'duration_ms' => $response->durationMs,
                'refined_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Run the full pipeline: draft + refine.
     */
    protected function generateFull(AIGatewayService $gateway): void
    {
        $gateway->generateAndRefine($this->brief, $this->context);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Content generation job failed permanently', [
            'brief_id' => $this->brief->id,
            'mode' => $this->mode,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $this->brief->markFailed(
            "Generation failed after {$this->attempts()} attempts: {$exception->getMessage()}"
        );
    }
}
