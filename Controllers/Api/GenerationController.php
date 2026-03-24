<?php

declare(strict_types=1);

namespace Core\Mod\Content\Controllers\Api;

use Core\Api\Concerns\HasApiResponses;
use Core\Api\Concerns\ResolvesWorkspace;
use Core\Front\Controller;
use Core\Mod\Content\Jobs\GenerateContentJob;
use Core\Mod\Content\Models\AIUsage;
use Core\Mod\Content\Models\ContentBrief;
use Core\Mod\Content\Resources\ContentBriefResource;
use Core\Mod\Content\Services\AIGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Content Generation API Controller
 *
 * Handles AI content generation requests.
 * Supports both synchronous and async generation.
 */
class GenerationController extends Controller
{
    use HasApiResponses;
    use ResolvesWorkspace;

    public function __construct(
        protected AIGatewayService $gateway
    ) {}

    /**
     * Generate draft content for a brief (Gemini).
     *
     * POST /api/v1/content/generate/draft
     */
    public function draft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'brief_id' => 'required|exists:content_briefs,id',
            'async' => 'boolean',
            'context' => 'nullable|array',
        ]);

        $brief = ContentBrief::findOrFail($validated['brief_id']);
        $workspace = $this->resolveWorkspace($request);

        // Check access
        if ($brief->workspace_id && $workspace?->id !== $brief->workspace_id) {
            return $this->accessDeniedResponse();
        }

        // Check if already generated
        if ($brief->isGenerated()) {
            return response()->json([
                'message' => 'Draft already generated.',
                'data' => new ContentBriefResource($brief),
            ]);
        }

        // Async generation
        if ($validated['async'] ?? false) {
            GenerateContentJob::dispatch($brief, 'draft', $validated['context'] ?? null);

            return response()->json([
                'message' => 'Draft generation queued.',
                'data' => new ContentBriefResource($brief->fresh()),
            ], 202);
        }

        // Sync generation
        try {
            if (! $this->gateway->isGeminiAvailable()) {
                return response()->json([
                    'error' => 'service_unavailable',
                    'message' => 'Gemini API is not configured.',
                ], 503);
            }

            $response = $this->gateway->generateDraft($brief, $validated['context'] ?? null);

            $brief->markDraftComplete($response->content, [
                'draft' => [
                    'model' => $response->model,
                    'tokens' => $response->totalTokens(),
                    'cost' => $response->estimateCost(),
                ],
            ]);

            return response()->json([
                'message' => 'Draft generated successfully.',
                'data' => new ContentBriefResource($brief->fresh()),
                'usage' => [
                    'model' => $response->model,
                    'input_tokens' => $response->inputTokens,
                    'output_tokens' => $response->outputTokens,
                    'cost_estimate' => $response->estimateCost(),
                    'duration_ms' => $response->durationMs,
                ],
            ]);
        } catch (\Exception $e) {
            $brief->markFailed($e->getMessage());

            return response()->json([
                'error' => 'generation_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Refine draft content (Claude).
     *
     * POST /api/v1/content/generate/refine
     */
    public function refine(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'brief_id' => 'required|exists:content_briefs,id',
            'async' => 'boolean',
            'context' => 'nullable|array',
        ]);

        $brief = ContentBrief::findOrFail($validated['brief_id']);
        $workspace = $this->resolveWorkspace($request);

        // Check access
        if ($brief->workspace_id && $workspace?->id !== $brief->workspace_id) {
            return $this->accessDeniedResponse();
        }

        // Check if draft exists
        if (! $brief->isGenerated()) {
            return response()->json([
                'error' => 'no_draft',
                'message' => 'No draft to refine. Generate a draft first.',
            ], 400);
        }

        // Check if already refined
        if ($brief->isRefined()) {
            return response()->json([
                'message' => 'Draft already refined.',
                'data' => new ContentBriefResource($brief),
            ]);
        }

        // Async refinement
        if ($validated['async'] ?? false) {
            GenerateContentJob::dispatch($brief, 'refine', $validated['context'] ?? null);

            return response()->json([
                'message' => 'Refinement queued.',
                'data' => new ContentBriefResource($brief->fresh()),
            ], 202);
        }

        // Sync refinement
        try {
            if (! $this->gateway->isClaudeAvailable()) {
                return response()->json([
                    'error' => 'service_unavailable',
                    'message' => 'Claude API is not configured.',
                ], 503);
            }

            $response = $this->gateway->refineDraft(
                $brief,
                $brief->draft_output,
                $validated['context'] ?? null
            );

            $brief->markRefined($response->content, [
                'refine' => [
                    'model' => $response->model,
                    'tokens' => $response->totalTokens(),
                    'cost' => $response->estimateCost(),
                ],
            ]);

            return response()->json([
                'message' => 'Draft refined successfully.',
                'data' => new ContentBriefResource($brief->fresh()),
                'usage' => [
                    'model' => $response->model,
                    'input_tokens' => $response->inputTokens,
                    'output_tokens' => $response->outputTokens,
                    'cost_estimate' => $response->estimateCost(),
                    'duration_ms' => $response->durationMs,
                ],
            ]);
        } catch (\Exception $e) {
            $brief->markFailed($e->getMessage());

            return response()->json([
                'error' => 'refinement_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Run the full pipeline: draft + refine.
     *
     * POST /api/v1/content/generate/full
     */
    public function full(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'brief_id' => 'required|exists:content_briefs,id',
            'async' => 'boolean',
            'context' => 'nullable|array',
        ]);

        $brief = ContentBrief::findOrFail($validated['brief_id']);
        $workspace = $this->resolveWorkspace($request);

        // Check access
        if ($brief->workspace_id && $workspace?->id !== $brief->workspace_id) {
            return $this->accessDeniedResponse();
        }

        // Async full generation
        if ($validated['async'] ?? false) {
            GenerateContentJob::dispatch($brief, 'full', $validated['context'] ?? null);

            return response()->json([
                'message' => 'Full generation pipeline queued.',
                'data' => new ContentBriefResource($brief->fresh()),
            ], 202);
        }

        // Sync full generation
        try {
            if (! $this->gateway->isAvailable()) {
                return response()->json([
                    'error' => 'service_unavailable',
                    'message' => 'AI services are not fully configured.',
                ], 503);
            }

            $result = $this->gateway->generateAndRefine($brief, $validated['context'] ?? null);

            return response()->json([
                'message' => 'Content generated and refined successfully.',
                'data' => new ContentBriefResource($result['brief']),
                'usage' => [
                    'draft' => [
                        'model' => $result['draft']->model,
                        'tokens' => $result['draft']->totalTokens(),
                        'cost' => $result['draft']->estimateCost(),
                    ],
                    'refine' => [
                        'model' => $result['refined']->model,
                        'tokens' => $result['refined']->totalTokens(),
                        'cost' => $result['refined']->estimateCost(),
                    ],
                    'total_cost' => $result['draft']->estimateCost() + $result['refined']->estimateCost(),
                ],
            ]);
        } catch (\Exception $e) {
            $brief->markFailed($e->getMessage());

            return response()->json([
                'error' => 'generation_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate social media posts from content.
     *
     * POST /api/v1/content/generate/social
     */
    public function socialPosts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required_without:brief_id|string',
            'brief_id' => 'required_without:content|exists:content_briefs,id',
            'platforms' => 'required|array|min:1',
            'platforms.*' => 'string|in:twitter,linkedin,facebook,instagram',
        ]);

        $workspace = $this->resolveWorkspace($request);
        $briefId = null;
        $content = $validated['content'] ?? null;

        // Get content from brief if provided
        if (isset($validated['brief_id'])) {
            $brief = ContentBrief::findOrFail($validated['brief_id']);

            // Check access
            if ($brief->workspace_id && $workspace?->id !== $brief->workspace_id) {
                return $this->accessDeniedResponse();
            }

            $content = $brief->best_content;
            $briefId = $brief->id;

            if (! $content) {
                return response()->json([
                    'error' => 'no_content',
                    'message' => 'Brief has no generated content.',
                ], 400);
            }
        }

        try {
            if (! $this->gateway->isClaudeAvailable()) {
                return response()->json([
                    'error' => 'service_unavailable',
                    'message' => 'Claude API is not configured.',
                ], 503);
            }

            $response = $this->gateway->generateSocialPosts(
                $content,
                $validated['platforms'],
                $workspace?->id,
                $briefId
            );

            // Parse JSON response
            $posts = [];
            if (preg_match('/```json\s*(.*?)\s*```/s', $response->content, $matches)) {
                $parsed = json_decode($matches[1], true);
                $posts = $parsed['posts'] ?? [];
            }

            return response()->json([
                'message' => 'Social posts generated successfully.',
                'data' => [
                    'posts' => $posts,
                    'raw' => $response->content,
                ],
                'usage' => [
                    'model' => $response->model,
                    'input_tokens' => $response->inputTokens,
                    'output_tokens' => $response->outputTokens,
                    'cost_estimate' => $response->estimateCost(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'generation_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve a brief's refined content and mark for publishing.
     *
     * POST /api/v1/content/briefs/{brief}/approve
     */
    public function approve(Request $request, ContentBrief $brief): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        // Check access
        if ($brief->workspace_id && $workspace?->id !== $brief->workspace_id) {
            return $this->accessDeniedResponse();
        }

        if ($brief->status !== ContentBrief::STATUS_REVIEW) {
            return response()->json([
                'error' => 'invalid_status',
                'message' => 'Brief must be in review status to approve.',
            ], 400);
        }

        $brief->markPublished(
            $brief->refined_output ?? $brief->draft_output
        );

        return response()->json([
            'message' => 'Content approved and ready for publishing.',
            'data' => new ContentBriefResource($brief),
        ]);
    }

    /**
     * Get AI usage statistics.
     *
     * GET /api/v1/content/usage
     */
    public function usage(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);
        $period = $request->input('period', 'month');

        $stats = AIUsage::statsForWorkspace($workspace?->id, $period);

        return response()->json([
            'data' => $stats,
            'period' => $period,
            'workspace_id' => $workspace?->id,
        ]);
    }
}
