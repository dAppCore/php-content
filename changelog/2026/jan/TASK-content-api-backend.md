# TASK: Content API Backend for AI Pipeline

**Status:** Ready for Implementation
**Priority:** P1
**Estimated Effort:** 2-3 weeks
**Dependencies:** Mod/Content (native CMS), external HTTP clients

---

## Overview

Build Laravel API endpoints at `host.uk.com` to orchestrate the AI content pipeline. This serves as the bridge between external requests, AI services, and the native CMS (Mod/Content).

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                      External Requests                               │
│   (Webhooks, API Calls, Scheduled Jobs)                             │
└─────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    host.uk.com Laravel API                          │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌────────────┐ │
│  │ Content      │ │ AI Gateway   │ │ Mod/Content  │ │ Mod/Social │ │
│  │ Briefs       │ │ (Gemini/     │ │ Native CMS   │ │ Scheduler  │ │
│  │ Queue        │ │ Claude)      │ │              │ │            │ │
│  └──────────────┘ └──────────────┘ └──────────────┘ └────────────┘ │
└─────────────────────────────────────────────────────────────────────┘
          │                │                │                │
          ▼                ▼                ▼                ▼
┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│   Database   │  │  Gemini/     │  │  Mod/Content │  │  Mod/Social  │
│   (Briefs,   │  │  Claude      │  │  ContentItem │  │  Posts &     │
│   Queue)     │  │  APIs        │  │  Model       │  │  Accounts    │
└──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘
```

---

## Database Schema

### Migration 1: Content Briefs

```php
// database/migrations/xxxx_create_content_briefs_table.php

Schema::create('content_briefs', function (Blueprint $table) {
    $table->id();
    $table->string('service'); // social, link, analytics, trust, notify
    $table->string('content_type'); // help_article, blog_post, landing_page
    $table->string('title');
    $table->string('slug')->nullable();
    $table->text('description')->nullable();
    $table->json('keywords')->nullable();
    $table->string('category')->nullable();
    $table->string('difficulty')->nullable(); // beginner, intermediate, advanced
    $table->integer('target_word_count')->default(1000);
    $table->json('prompt_variables')->nullable(); // Additional context
    $table->string('status')->default('pending'); // pending, queued, generating, review, published
    $table->integer('priority')->default(50); // 1-100, higher = more urgent
    $table->timestamp('scheduled_for')->nullable();
    $table->timestamps();

    $table->index(['service', 'status']);
    $table->index(['status', 'priority']);
});
```

### Migration 2: Content Queue

```php
// database/migrations/xxxx_create_content_queue_table.php

Schema::create('content_queue', function (Blueprint $table) {
    $table->id();
    $table->foreignId('brief_id')->constrained('content_briefs')->onDelete('cascade');
    $table->string('stage'); // draft, refine, review, publish
    $table->text('gemini_output')->nullable();
    $table->text('claude_output')->nullable();
    $table->text('final_content')->nullable();
    $table->json('metadata')->nullable(); // frontmatter, seo data
    $table->integer('content_item_id')->nullable();
    $table->string('content_status')->nullable();
    $table->json('generation_log')->nullable(); // Track AI calls, costs
    $table->timestamp('generated_at')->nullable();
    $table->timestamp('refined_at')->nullable();
    $table->timestamp('published_at')->nullable();
    $table->timestamps();

    $table->index('stage');
});
```

### Migration 3: Social Queue

```php
// database/migrations/xxxx_create_social_queue_table.php

Schema::create('social_queue', function (Blueprint $table) {
    $table->id();
    $table->foreignId('content_id')->nullable()->constrained('content_queue')->onDelete('set null');
    $table->string('platform'); // twitter, linkedin, instagram, facebook
    $table->text('content');
    $table->string('media_url')->nullable();
    $table->json('metadata')->nullable();
    $table->timestamp('scheduled_for');
    $table->string('status')->default('pending'); // pending, scheduled, published, failed
    $table->string('social_post_id')->nullable();
    $table->timestamps();

    $table->index(['platform', 'status']);
    $table->index('scheduled_for');
});
```

### Migration 4: AI Usage Tracking

```php
// database/migrations/xxxx_create_ai_usage_table.php

Schema::create('ai_usage', function (Blueprint $table) {
    $table->id();
    $table->string('provider'); // gemini, claude, openai
    $table->string('model');
    $table->string('purpose'); // draft, refine, social, image
    $table->integer('input_tokens')->default(0);
    $table->integer('output_tokens')->default(0);
    $table->decimal('cost_estimate', 10, 6)->default(0);
    $table->foreignId('brief_id')->nullable()->constrained('content_briefs')->onDelete('set null');
    $table->json('metadata')->nullable();
    $table->timestamps();

    $table->index(['provider', 'created_at']);
});
```

---

## API Endpoints

### Content Briefs API

```php
// routes/api.php

Route::prefix('content')->middleware(['auth:sanctum'])->group(function () {
    // Briefs
    Route::get('briefs', [ContentBriefController::class, 'index']);
    Route::post('briefs', [ContentBriefController::class, 'store']);
    Route::get('briefs/{brief}', [ContentBriefController::class, 'show']);
    Route::put('briefs/{brief}', [ContentBriefController::class, 'update']);
    Route::delete('briefs/{brief}', [ContentBriefController::class, 'destroy']);
    Route::post('briefs/bulk', [ContentBriefController::class, 'bulkStore']);
    Route::get('briefs/next', [ContentBriefController::class, 'next']); // For external callers

    // Queue
    Route::get('queue', [ContentQueueController::class, 'index']);
    Route::get('queue/{item}', [ContentQueueController::class, 'show']);
    Route::post('queue/{item}/generate', [ContentQueueController::class, 'generate']);
    Route::post('queue/{item}/refine', [ContentQueueController::class, 'refine']);
    Route::post('queue/{item}/publish', [ContentQueueController::class, 'publish']);
    Route::post('queue/{item}/approve', [ContentQueueController::class, 'approve']);
    Route::post('queue/{item}/reject', [ContentQueueController::class, 'reject']);

    // Generation (for external callers)
    Route::post('generate/draft', [GenerationController::class, 'draft']);
    Route::post('generate/refine', [GenerationController::class, 'refine']);
    Route::post('generate/social', [GenerationController::class, 'socialPosts']);
});
```

### Content Integration API (Mod/Content)

```php
Route::prefix('content-items')->middleware(['auth:sanctum'])->group(function () {
    Route::get('sites', [ContentController::class, 'sites']);
    Route::get('items/{site}', [ContentController::class, 'items']);
    Route::post('items/{site}', [ContentController::class, 'createItem']);
    Route::put('items/{site}/{id}', [ContentController::class, 'updateItem']);
    Route::post('items/{site}/{id}/publish', [ContentController::class, 'publishItem']);
    Route::delete('items/{site}/{id}', [ContentController::class, 'deleteItem']);
    Route::get('categories/{site}', [ContentController::class, 'categories']);
    Route::get('media/{site}', [ContentController::class, 'media']);
});
```

### Social Scheduling API

```php
Route::prefix('social')->middleware(['auth:sanctum'])->group(function () {
    Route::get('queue', [SocialQueueController::class, 'index']);
    Route::post('queue', [SocialQueueController::class, 'store']);
    Route::post('queue/bulk', [SocialQueueController::class, 'bulkStore']);
    Route::put('queue/{item}', [SocialQueueController::class, 'update']);
    Route::delete('queue/{item}', [SocialQueueController::class, 'destroy']);
    Route::post('queue/{item}/schedule', [SocialQueueController::class, 'schedule']);
});
```

### Analytics/Stats API

```php
Route::prefix('stats')->middleware(['auth:sanctum'])->group(function () {
    Route::get('content', [StatsController::class, 'contentStats']);
    Route::get('ai-usage', [StatsController::class, 'aiUsage']);
    Route::get('pipeline', [StatsController::class, 'pipelineStatus']);
});
```

---

## Services

### AI Gateway Service

```php
// app/Services/AIGatewayService.php

<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Models\AIUsage;

class AIGatewayService
{
    public function __construct(
        protected string $geminiApiKey,
        protected string $claudeApiKey
    ) {}

    /**
     * Generate content draft using Gemini
     */
    public function generateDraft(string $prompt, array $options = []): array
    {
        $startTime = microtime(true);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post("https://generativelanguage.googleapis.com/v1/models/gemini-pro:generateContent?key={$this->geminiApiKey}", [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.7,
                'maxOutputTokens' => $options['max_tokens'] ?? 4096,
            ]
        ]);

        $result = $response->json();

        // Log usage
        $this->logUsage('gemini', 'gemini-pro', 'draft', $prompt, $result, $options['brief_id'] ?? null);

        return [
            'success' => $response->successful(),
            'content' => $result['candidates'][0]['content']['parts'][0]['text'] ?? null,
            'raw' => $result,
            'duration' => microtime(true) - $startTime,
        ];
    }

    /**
     * Refine content using Claude
     */
    public function refineContent(string $content, string $refinementPrompt, array $options = []): array
    {
        $startTime = microtime(true);

        $response = Http::withHeaders([
            'x-api-key' => $this->claudeApiKey,
            'anthropic-version' => '2024-01-01',
            'Content-Type' => 'application/json',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model' => $options['model'] ?? 'claude-3-opus-20240229',
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $refinementPrompt . "\n\n---\n\n" . $content
                ]
            ]
        ]);

        $result = $response->json();

        // Log usage
        $this->logUsage('claude', $options['model'] ?? 'claude-3-opus', 'refine', $refinementPrompt, $result, $options['brief_id'] ?? null);

        return [
            'success' => $response->successful(),
            'content' => $result['content'][0]['text'] ?? null,
            'raw' => $result,
            'duration' => microtime(true) - $startTime,
        ];
    }

    /**
     * Generate social media posts from content
     */
    public function generateSocialPosts(string $content, string $title, string $url): array
    {
        $prompt = <<<PROMPT
        Generate social media posts for this content:

        Title: {$title}
        URL: {$url}

        Content summary:
        {$content}

        Generate posts for:
        1. Twitter (280 chars max, include URL)
        2. LinkedIn (300 words, professional tone)
        3. Facebook (150 words, conversational)

        Return as JSON: {"twitter": "...", "linkedin": "...", "facebook": "..."}
        PROMPT;

        return $this->generateDraft($prompt, ['temperature' => 0.8]);
    }

    protected function logUsage(string $provider, string $model, string $purpose, string $input, array $result, ?int $briefId): void
    {
        // Estimate tokens (rough calculation)
        $inputTokens = (int) (strlen($input) / 4);
        $outputTokens = isset($result['content'][0]['text'])
            ? (int) (strlen($result['content'][0]['text']) / 4)
            : (isset($result['candidates'][0]['content']['parts'][0]['text'])
                ? (int) (strlen($result['candidates'][0]['content']['parts'][0]['text']) / 4)
                : 0);

        // Cost estimates (approximate)
        $costPerInputToken = match($provider) {
            'gemini' => 0.000001,
            'claude' => 0.000015,
            default => 0.00001,
        };
        $costPerOutputToken = match($provider) {
            'gemini' => 0.000002,
            'claude' => 0.000075,
            default => 0.00003,
        };

        AIUsage::create([
            'provider' => $provider,
            'model' => $model,
            'purpose' => $purpose,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_estimate' => ($inputTokens * $costPerInputToken) + ($outputTokens * $costPerOutputToken),
            'brief_id' => $briefId,
            'metadata' => [
                'usage' => $result['usage'] ?? null,
            ],
        ]);
    }
}
```

### Content Client Service (Mod/Content)

```php
// app/Mod/Content/Services/ContentClientService.php

<?php

namespace Mod\Content\Services;

use Mod\Content\Models\ContentItem;
use Mod\Content\Models\ContentSite;
use Illuminate\Support\Facades\Cache;

class ContentClientService
{
    protected array $sites = [
        'social' => 'social.host.uk.com',
        'link' => 'link.host.uk.com',
        'analytics' => 'analytics.host.uk.com',
        'trust' => 'trust.host.uk.com',
        'notify' => 'notify.host.uk.com',
    ];

    public function getItems(string $site, array $params = []): array
    {
        $cacheKey = "content_items_{$site}_" . md5(serialize($params));

        return Cache::remember($cacheKey, 300, function () use ($site, $params) {
            return ContentItem::query()
                ->where('site', $site)
                ->when($params['status'] ?? null, fn($q, $s) => $q->where('status', $s))
                ->limit($params['per_page'] ?? 20)
                ->get()
                ->toArray();
        });
    }

    public function createItem(string $site, array $data): array
    {
        $item = ContentItem::create([
            'site' => $site,
            'title' => $data['title'],
            'content' => $data['content'],
            'excerpt' => $data['excerpt'] ?? '',
            'status' => $data['status'] ?? 'draft',
            'categories' => $data['categories'] ?? [],
            'tags' => $data['tags'] ?? [],
            'meta' => $data['meta'] ?? [],
        ]);

        // Clear cache
        Cache::forget("content_items_{$site}_*");

        return [
            'success' => true,
            'item' => $item->toArray(),
            'id' => $item->id,
        ];
    }

    public function updateItem(string $site, int $itemId, array $data): array
    {
        $item = ContentItem::where('site', $site)->findOrFail($itemId);
        $item->update($data);

        Cache::forget("content_items_{$site}_*");

        return [
            'success' => true,
            'item' => $item->fresh()->toArray(),
        ];
    }

    public function publishItem(string $site, int $itemId): array
    {
        return $this->updateItem($site, $itemId, ['status' => 'published']);
    }

    public function getCategories(string $site): array
    {
        return Cache::remember("content_categories_{$site}", 3600, function () use ($site) {
            return ContentItem::where('site', $site)
                ->distinct('category')
                ->pluck('category')
                ->toArray();
        });
    }
}
```

### Social Scheduler Service (Mod/Social)

```php
// app/Mod/Social/Services/SocialSchedulerService.php

<?php

namespace Mod\Social\Services;

use Mod\Social\Models\Post;
use Mod\Social\Models\Account;

class SocialSchedulerService
{
    public function schedulePost(array $data): array
    {
        $post = Post::create([
            'content' => $data['content'],
            'media' => $data['media'] ?? [],
            'accounts' => $data['accounts'], // Platform account IDs
            'scheduled_at' => $data['scheduled_at'],
            'status' => 'scheduled',
        ]);

        return [
            'success' => true,
            'post' => $post->toArray(),
            'id' => $post->id,
        ];
    }

    public function getAccounts(): array
    {
        return Account::all()->toArray();
    }

    public function getScheduledPosts(array $params = []): array
    {
        return Post::query()
            ->where('status', 'scheduled')
            ->when($params['account_id'] ?? null, fn($q, $id) => $q->where('account_id', $id))
            ->orderBy('scheduled_at')
            ->get()
            ->toArray();
    }
}
```

---

## Controllers

### Content Brief Controller

```php
// app/Http/Controllers/Api/ContentBriefController.php

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentBrief;
use App\Http\Requests\ContentBriefRequest;
use Illuminate\Http\Request;

class ContentBriefController extends Controller
{
    public function index(Request $request)
    {
        $briefs = ContentBrief::query()
            ->when($request->service, fn($q, $s) => $q->where('service', $s))
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->type, fn($q, $t) => $q->where('content_type', $t))
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'asc')
            ->paginate($request->per_page ?? 20);

        return response()->json($briefs);
    }

    public function store(ContentBriefRequest $request)
    {
        $brief = ContentBrief::create($request->validated());

        return response()->json($brief, 201);
    }

    public function bulkStore(Request $request)
    {
        $request->validate([
            'briefs' => 'required|array',
            'briefs.*.service' => 'required|string',
            'briefs.*.content_type' => 'required|string',
            'briefs.*.title' => 'required|string',
        ]);

        $briefs = collect($request->briefs)->map(function ($data) {
            return ContentBrief::create($data);
        });

        return response()->json([
            'created' => $briefs->count(),
            'briefs' => $briefs,
        ], 201);
    }

    /**
     * Get next brief to process (for external callers)
     */
    public function next(Request $request)
    {
        $brief = ContentBrief::query()
            ->where('status', 'pending')
            ->when($request->service, fn($q, $s) => $q->where('service', $s))
            ->orderBy('priority', 'desc')
            ->orderBy('scheduled_for', 'asc')
            ->orderBy('created_at', 'asc')
            ->first();

        if (!$brief) {
            return response()->json(['message' => 'No briefs pending'], 404);
        }

        // Mark as queued
        $brief->update(['status' => 'queued']);

        return response()->json($brief);
    }

    public function show(ContentBrief $brief)
    {
        return response()->json($brief->load('queueItem'));
    }

    public function update(ContentBriefRequest $request, ContentBrief $brief)
    {
        $brief->update($request->validated());

        return response()->json($brief);
    }

    public function destroy(ContentBrief $brief)
    {
        $brief->delete();

        return response()->json(null, 204);
    }
}
```

### Generation Controller (for external callers)

```php
// app/Http/Controllers/Api/GenerationController.php

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AIGatewayService;
use Mod\Content\Services\ContentClientService;
use App\Models\ContentBrief;
use App\Models\ContentQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GenerationController extends Controller
{
    public function __construct(
        protected AIGatewayService $ai,
        protected ContentClientService $content
    ) {}

    /**
     * Generate draft content (Gemini)
     */
    public function draft(Request $request)
    {
        $request->validate([
            'brief_id' => 'required|exists:content_briefs,id',
        ]);

        $brief = ContentBrief::findOrFail($request->brief_id);

        // Load prompt template
        $promptTemplate = $this->getPromptTemplate($brief->content_type);
        $prompt = $this->buildPrompt($promptTemplate, $brief);

        // Generate with Gemini
        $result = $this->ai->generateDraft($prompt, [
            'brief_id' => $brief->id,
        ]);

        if (!$result['success']) {
            return response()->json(['error' => 'Generation failed', 'details' => $result], 500);
        }

        // Create or update queue item
        $queueItem = ContentQueue::updateOrCreate(
            ['brief_id' => $brief->id],
            [
                'stage' => 'draft',
                'gemini_output' => $result['content'],
                'generated_at' => now(),
                'generation_log' => [
                    'gemini' => [
                        'duration' => $result['duration'],
                        'timestamp' => now()->toIso8601String(),
                    ]
                ],
            ]
        );

        $brief->update(['status' => 'generating']);

        return response()->json([
            'success' => true,
            'queue_item' => $queueItem,
            'content' => $result['content'],
        ]);
    }

    /**
     * Refine content (Claude)
     */
    public function refine(Request $request)
    {
        $request->validate([
            'queue_id' => 'required|exists:content_queue,id',
        ]);

        $queueItem = ContentQueue::with('brief')->findOrFail($request->queue_id);

        if (!$queueItem->gemini_output) {
            return response()->json(['error' => 'No draft to refine'], 400);
        }

        // Load refinement prompt
        $refinementPrompt = $this->getRefinementPrompt($queueItem->brief->content_type);

        // Refine with Claude
        $result = $this->ai->refineContent(
            $queueItem->gemini_output,
            $refinementPrompt,
            ['brief_id' => $queueItem->brief_id]
        );

        if (!$result['success']) {
            return response()->json(['error' => 'Refinement failed', 'details' => $result], 500);
        }

        // Update queue item
        $queueItem->update([
            'stage' => 'review',
            'claude_output' => $result['content'],
            'final_content' => $result['content'],
            'refined_at' => now(),
            'generation_log' => array_merge($queueItem->generation_log ?? [], [
                'claude' => [
                    'duration' => $result['duration'],
                    'timestamp' => now()->toIso8601String(),
                ]
            ]),
        ]);

        $queueItem->brief->update(['status' => 'review']);

        return response()->json([
            'success' => true,
            'queue_item' => $queueItem->fresh(),
            'content' => $result['content'],
        ]);
    }

    /**
     * Generate social posts for content
     */
    public function socialPosts(Request $request)
    {
        $request->validate([
            'queue_id' => 'required|exists:content_queue,id',
            'url' => 'required|url',
        ]);

        $queueItem = ContentQueue::with('brief')->findOrFail($request->queue_id);

        $result = $this->ai->generateSocialPosts(
            substr($queueItem->final_content, 0, 2000), // Summary
            $queueItem->brief->title,
            $request->url
        );

        if (!$result['success']) {
            return response()->json(['error' => 'Social generation failed'], 500);
        }

        $posts = json_decode($result['content'], true);

        return response()->json([
            'success' => true,
            'posts' => $posts,
        ]);
    }

    protected function getPromptTemplate(string $type): string
    {
        $templates = [
            'help_article' => Storage::disk('local')->get('prompts/help-article.txt'),
            'blog_post' => Storage::disk('local')->get('prompts/blog-post.txt'),
            'landing_page' => Storage::disk('local')->get('prompts/landing-page.txt'),
        ];

        return $templates[$type] ?? $templates['help_article'];
    }

    protected function getRefinementPrompt(string $type): string
    {
        return Storage::disk('local')->get('prompts/refinement.txt');
    }

    protected function buildPrompt(string $template, ContentBrief $brief): string
    {
        $variables = array_merge([
            'SERVICE_NAME' => $this->getServiceName($brief->service),
            'SERVICE_URL' => $this->getServiceUrl($brief->service),
            'TITLE' => $brief->title,
            'DESCRIPTION' => $brief->description ?? '',
            'KEYWORDS' => implode(', ', $brief->keywords ?? []),
            'CATEGORY' => $brief->category ?? '',
            'DIFFICULTY' => $brief->difficulty ?? 'beginner',
            'WORD_COUNT' => $brief->target_word_count,
        ], $brief->prompt_variables ?? []);

        foreach ($variables as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }

        return $template;
    }

    protected function getServiceName(string $service): string
    {
        return match($service) {
            'social' => 'Host Social',
            'link' => 'Host Link',
            'analytics' => 'Host Analytics',
            'trust' => 'Host Trust',
            'notify' => 'Host Notify',
            default => 'Host UK',
        };
    }

    protected function getServiceUrl(string $service): string
    {
        return match($service) {
            'social' => 'social.host.uk.com',
            'link' => 'link.host.uk.com',
            'analytics' => 'analytics.host.uk.com',
            'trust' => 'trust.host.uk.com',
            'notify' => 'notify.host.uk.com',
            default => 'host.uk.com',
        };
    }
}
```

---

## Configuration

### Environment Variables

```env
# AI Services
GEMINI_API_KEY=your_gemini_api_key
ANTHROPIC_API_KEY=your_claude_api_key

# External Webhook Secret (for validation)
CONTENT_WEBHOOK_SECRET=your_webhook_secret
```

### Config File

```php
// config/content.php

return [
    'ai' => [
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => 'gemini-pro',
            'max_tokens' => 4096,
        ],
        'claude' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => 'claude-3-opus-20240229',
            'max_tokens' => 4096,
        ],
    ],

    'sites' => [
        'social' => 'social.host.uk.com',
        'link' => 'link.host.uk.com',
        'analytics' => 'analytics.host.uk.com',
        'trust' => 'trust.host.uk.com',
        'notify' => 'notify.host.uk.com',
    ],

    'webhook' => [
        'secret' => env('CONTENT_WEBHOOK_SECRET'),
    ],

    'defaults' => [
        'word_count' => 1000,
        'priority' => 50,
    ],
];
```

---

## Implementation Checklist

### Phase 1: Core Infrastructure (Week 1)

- [ ] **Database**
  - [ ] Create migrations
  - [ ] Create models with relationships
  - [ ] Create factories for testing

- [ ] **Services**
  - [ ] AIGatewayService (Gemini + Claude)
  - [ ] ContentClientService (Mod/Content)
  - [ ] SocialSchedulerService (Mod/Social)

- [ ] **Basic API**
  - [ ] ContentBrief CRUD
  - [ ] ContentQueue management
  - [ ] Authentication (Sanctum)

### Phase 2: Generation Pipeline (Week 2)

- [ ] **Generation Controller**
  - [ ] Draft endpoint (Gemini)
  - [ ] Refine endpoint (Claude)
  - [ ] Social posts endpoint

- [ ] **Prompt Management**
  - [ ] Store prompt templates
  - [ ] Variable substitution
  - [ ] Service-specific prompts

- [ ] **Content Publishing (Mod/Content)**
  - [ ] Create draft items
  - [ ] Update existing items
  - [ ] Publish workflow

### Phase 3: Integration (Week 3)

- [ ] **External Webhooks**
  - [ ] Webhook endpoints for external callers
  - [ ] Secret validation
  - [ ] Response formatting

- [ ] **Social Integration (Mod/Social)**
  - [ ] Schedule social posts
  - [ ] Account management
  - [ ] Post status tracking

- [ ] **Monitoring**
  - [ ] AI usage tracking
  - [ ] Cost reporting
  - [ ] Error logging

### Phase 4: Polish

- [ ] **Admin Dashboard** (optional)
  - [ ] Brief management UI
  - [ ] Queue status view
  - [ ] Analytics dashboard

- [ ] **Testing**
  - [ ] Unit tests for services
  - [ ] Feature tests for API
  - [ ] Integration tests

- [ ] **Documentation**
  - [ ] API documentation
  - [ ] External integration examples
  - [ ] Deployment guide

---

## External Integration Endpoints Summary

| Endpoint                         | Method | Purpose                    |
|----------------------------------|--------|----------------------------|
| `/api/content/briefs/next`       | GET    | Get next brief to process  |
| `/api/content/generate/draft`    | POST   | Generate draft with Gemini |
| `/api/content/generate/refine`   | POST   | Refine with Claude         |
| `/api/content/generate/social`   | POST   | Generate social posts      |
| `/api/content/queue/{id}/publish`| POST   | Publish to Mod/Content     |
| `/api/social/queue`              | POST   | Schedule via Mod/Social    |

---

## File Structure

```
app/
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       ├── ContentBriefController.php
│   │       ├── ContentQueueController.php
│   │       ├── GenerationController.php
│   │       ├── SocialQueueController.php
│   │       └── StatsController.php
│   └── Requests/
│       └── ContentBriefRequest.php
├── Models/
│   ├── ContentBrief.php
│   ├── ContentQueue.php
│   ├── SocialQueue.php
│   └── AIUsage.php
├── Mod/
│   ├── Content/
│   │   └── Services/
│   │       └── ContentClientService.php
│   └── Social/
│       └── Services/
│           └── SocialSchedulerService.php
└── Services/
    └── AIGatewayService.php

database/
└── migrations/
    ├── xxxx_create_content_briefs_table.php
    ├── xxxx_create_content_queue_table.php
    ├── xxxx_create_social_queue_table.php
    └── xxxx_create_ai_usage_table.php

storage/
└── app/
    └── prompts/
        ├── help-article.txt
        ├── blog-post.txt
        ├── landing-page.txt
        └── refinement.txt

config/
└── content.php
```
