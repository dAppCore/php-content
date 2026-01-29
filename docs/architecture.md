---
title: Architecture
description: Technical architecture of the core-content package
updated: 2026-01-29
---

# Architecture

The `core-content` package provides headless CMS functionality for the Host UK platform. It handles content management, AI-powered generation, revision history, webhooks for external CMS integration, and search capabilities.

## Package Overview

**Namespace:** `Core\Mod\Content\`
**Entry Point:** `Boot.php` (Laravel Service Provider)
**Dependencies:**
- `core-php` (Foundation framework, events)
- `core-tenant` (Workspaces, users, entitlements)
- Optional: `core-agentic` (AI services for content generation)
- Optional: `core-mcp` (MCP tool handlers)

## Directory Structure

```
core-content/
├── Boot.php              # Service provider with event listeners
├── config.php            # Package configuration
├── Models/               # Eloquent models (10 models)
├── Services/             # Business logic services
├── Controllers/          # API and web controllers
│   └── Api/              # REST API controllers
├── Jobs/                 # Queue jobs
├── Mcp/                  # MCP tool handlers
│   └── Handlers/         # Individual MCP tools
├── Concerns/             # Traits
├── Console/              # Artisan commands
│   └── Commands/         # Command implementations
├── Enums/                # PHP enums
├── Migrations/           # Database migrations
├── Observers/            # Model observers
├── routes/               # Route definitions
├── View/                 # Livewire components and Blade views
│   ├── Modal/            # Livewire components
│   └── Blade/            # Blade templates
├── tests/                # Test suite
└── docs/                 # Documentation
```

## Core Concepts

### Content Items

The primary content model. Supports multiple content types and sources:

```php
// Content types (where content originates)
enum ContentType: string {
    case NATIVE = 'native';       // Created in Host Hub editor
    case HOSTUK = 'hostuk';       // Alias for native (backwards compat)
    case SATELLITE = 'satellite'; // Per-service content
    case WORDPRESS = 'wordpress'; // Legacy synced content
}
```

Content items belong to workspaces and have:
- Title, slug, excerpt, content (HTML/Markdown/JSON)
- Status (draft, publish, future, private, pending)
- Author and last editor tracking
- Revision history
- Taxonomy (categories, tags)
- SEO metadata
- Preview tokens for sharing unpublished content
- CDN cache invalidation tracking

### Content Briefs

Briefs drive AI-powered content generation. They define what content to create:

```php
// Brief content types (what to generate)
enum BriefContentType: string {
    case HELP_ARTICLE = 'help_article';   // Documentation
    case BLOG_POST = 'blog_post';         // Blog articles
    case LANDING_PAGE = 'landing_page';   // Marketing pages
    case SOCIAL_POST = 'social_post';     // Social media
}
```

Brief workflow: `pending` -> `queued` -> `generating` -> `review` -> `published`

### Revisions

Every content change creates an immutable revision snapshot. Revisions support:
- Change type tracking (edit, autosave, restore, publish)
- Word/character count tracking
- Side-by-side diff comparison with LCS algorithm
- Configurable retention policies (max count, max age)

## Service Layer

### AIGatewayService

Orchestrates two-stage AI content generation:

1. **Stage 1: Draft (Gemini)** - Fast, cost-effective initial generation
2. **Stage 2: Refine (Claude)** - Quality refinement and brand voice alignment

```php
$gateway = app(AIGatewayService::class);

// Two-stage pipeline
$result = $gateway->generateAndRefine($brief);

// Or individual stages
$draft = $gateway->generateDraft($brief);
$refined = $gateway->refineDraft($brief, $draftContent);

// Direct Claude generation (skip Gemini)
$content = $gateway->generateDirect($brief);
```

### ContentSearchService

Full-text search with multiple backend support:

```php
// Backends (configured via CONTENT_SEARCH_BACKEND)
const BACKEND_DATABASE = 'database';        // LIKE queries with relevance
const BACKEND_SCOUT_DATABASE = 'scout_database';  // Laravel Scout
const BACKEND_MEILISEARCH = 'meilisearch';  // Laravel Scout + Meilisearch
```

Features:
- Relevance scoring (title > slug > excerpt > content)
- Filters: type, status, category, tag, date range, content_type
- Autocomplete suggestions
- Re-indexing support for Scout backends

### WebhookRetryService

Handles failed webhook processing with exponential backoff:

```
Retry intervals: 1m, 5m, 15m, 1h, 4h
Max retries: 5 (configurable per webhook)
```

### ContentRender

Public-facing content renderer with caching:
- Homepage, blog listing, post, page rendering
- Cache TTL: 1 hour production, 1 minute development
- Cache key sanitisation for special characters

### CdnPurgeService

CDN cache invalidation via Bunny CDN:
- Triggered by ContentItemObserver on publish/update
- URL-based and tag-based purging
- Workspace-level cache clearing

## Event-Driven Architecture

The package uses the event-driven module loading pattern from `core-php`:

```php
class Boot extends ServiceProvider
{
    public static array $listens = [
        WebRoutesRegistering::class => 'onWebRoutes',
        ApiRoutesRegistering::class => 'onApiRoutes',
        ConsoleBooting::class => 'onConsole',
        McpToolsRegistering::class => 'onMcpTools',
    ];
}
```

Handlers register:
- **Web Routes:** Public blog, help pages, content preview
- **API Routes:** REST API for briefs, media, search, generation
- **Console:** Artisan commands for scheduling, pruning
- **MCP Tools:** AI agent content management tools

## API Structure

### Authenticated Endpoints (Session or API Key)

```
# Content Briefs
GET    /api/content/briefs           # List briefs
POST   /api/content/briefs           # Create brief
GET    /api/content/briefs/{id}      # Get brief
PUT    /api/content/briefs/{id}      # Update brief
DELETE /api/content/briefs/{id}      # Delete brief
POST   /api/content/briefs/bulk      # Bulk create
GET    /api/content/briefs/next      # Next ready for processing

# AI Generation (rate limited: 10/min)
POST   /api/content/generate/draft   # Generate draft (Gemini)
POST   /api/content/generate/refine  # Refine draft (Claude)
POST   /api/content/generate/full    # Full pipeline
POST   /api/content/generate/social  # Social posts from content

# Content Search (rate limited: 60/min)
GET    /api/content/search           # Full-text search
GET    /api/content/search/suggest   # Autocomplete
GET    /api/content/search/info      # Backend info
POST   /api/content/search/reindex   # Trigger re-index

# Revisions
GET    /api/content/items/{id}/revisions    # List revisions
GET    /api/content/revisions/{id}          # Get revision
POST   /api/content/revisions/{id}/restore  # Restore revision
GET    /api/content/revisions/{id}/compare/{other}  # Compare

# Preview
POST   /api/content/items/{id}/preview/generate  # Generate preview link
DELETE /api/content/items/{id}/preview/revoke    # Revoke preview link
```

### Public Endpoints

```
# Webhooks (signature verified, no auth)
POST   /api/content/webhooks/{endpoint}  # Receive external webhooks

# Web Routes
GET    /blog                             # Blog listing
GET    /blog/{slug}                      # Blog post
GET    /help                             # Help centre
GET    /help/{slug}                      # Help article
GET    /content/preview/{id}             # Preview content
```

## Rate Limiting

Defined in `Boot::configureRateLimiting()`:

| Limiter | Authenticated | Unauthenticated |
|---------|---------------|-----------------|
| `content-generate` | 10/min per user/workspace | 2/min per IP |
| `content-briefs` | 30/min per user | 5/min per IP |
| `content-webhooks` | 60/min per endpoint | 30/min per IP |
| `content-search` | Configurable (default 60/min) | 20/min per IP |

## MCP Tools

Seven MCP tools for AI agent integration:

| Tool | Description |
|------|-------------|
| `content_list` | List content items with filters |
| `content_read` | Read content by ID or slug |
| `content_search` | Full-text search |
| `content_create` | Create new content |
| `content_update` | Update existing content |
| `content_delete` | Soft delete content |
| `content_taxonomies` | List categories and tags |

All tools:
- Require workspace resolution
- Check entitlements (`content.mcp_access`, `content.items`)
- Log actions to MCP session
- Return structured responses

## Data Flow

### Content Creation via MCP

```
Agent Request
    ↓
ContentCreateHandler::handle()
    ↓
resolveWorkspace() → Workspace model
    ↓
checkEntitlement() → EntitlementService
    ↓
ContentItem::create()
    ↓
createRevision() → ContentRevision
    ↓
recordUsage() → EntitlementService
    ↓
Response with content ID
```

### Webhook Processing

```
External CMS
    ↓
POST /api/content/webhooks/{endpoint}
    ↓
ContentWebhookController::receive()
    ↓
Verify signature → ContentWebhookEndpoint::verifySignature()
    ↓
Check type allowed → ContentWebhookEndpoint::isTypeAllowed()
    ↓
Create ContentWebhookLog
    ↓
Dispatch ProcessContentWebhook job
    ↓
Job::handle()
    ↓
Process based on event type (wordpress.*, cms.*, generic.*)
    ↓
Create/Update/Delete ContentItem
    ↓
Mark log completed
```

### AI Generation Pipeline

```
ContentBrief
    ↓
GenerateContentJob dispatched
    ↓
Stage 1: AIGatewayService::generateDraft()
    ↓
GeminiService::generate() → Draft content
    ↓
Brief::markDraftComplete()
    ↓
Stage 2: AIGatewayService::refineDraft()
    ↓
ClaudeService::generate() → Refined content
    ↓
Brief::markRefined()
    ↓
AIUsage records created for each stage
```

## Configuration

Key settings in `config.php`:

```php
return [
    'generation' => [
        'default_timeout' => env('CONTENT_GENERATION_TIMEOUT', 300),
        'timeouts' => [
            'help_article' => 180,
            'blog_post' => 240,
            'landing_page' => 300,
            'social_post' => 60,
        ],
        'max_retries' => 3,
        'backoff' => [30, 60, 120],
    ],
    'revisions' => [
        'max_per_item' => env('CONTENT_MAX_REVISIONS', 50),
        'max_age_days' => 180,
        'preserve_published' => true,
    ],
    'cache' => [
        'ttl' => env('CONTENT_CACHE_TTL', 3600),
        'prefix' => 'content:render',
    ],
    'search' => [
        'backend' => env('CONTENT_SEARCH_BACKEND', 'database'),
        'min_query_length' => 2,
        'max_per_page' => 50,
        'default_per_page' => 20,
        'rate_limit' => 60,
    ],
];
```

## Database Schema

### Primary Tables

| Table | Purpose |
|-------|---------|
| `content_items` | Content storage (posts, pages) |
| `content_revisions` | Version history |
| `content_taxonomies` | Categories and tags |
| `content_item_taxonomy` | Pivot table |
| `content_media` | Media attachments |
| `content_authors` | Author profiles |
| `content_briefs` | AI generation briefs |
| `content_tasks` | Scheduled content tasks |
| `content_webhook_endpoints` | Webhook configurations |
| `content_webhook_logs` | Webhook processing logs |
| `ai_usage` | AI API usage tracking |
| `prompts` | AI prompt templates |
| `prompt_versions` | Prompt version history |

### Key Indexes

- `content_items`: Composite indexes on `(workspace_id, slug, type)`, `(workspace_id, status, type)`, `(workspace_id, status, content_type)`
- `content_revisions`: Index on `(content_item_id, revision_number)`
- `content_webhook_logs`: Index on `(workspace_id, status)`, `(status, created_at)`

## Extension Points

### Adding New Content Types

1. Add value to `ContentType` enum
2. Update `ContentType::isNative()` if applicable
3. Add any type-specific scopes to `ContentItem`

### Adding New AI Generation Types

1. Add value to `BriefContentType` enum
2. Add timeout to `config.php` generation.timeouts
3. Add prompt in `AIGatewayService::getDraftSystemPrompt()`

### Adding New Webhook Event Types

1. Add to `ContentWebhookEndpoint::ALLOWED_TYPES`
2. Add handler in `ProcessContentWebhook::processWordPress()` or `processCms()`
3. Add event type mapping in `ContentWebhookController::normaliseEventType()`

### Adding New MCP Tools

1. Create handler in `Mcp/Handlers/` implementing `McpToolHandler`
2. Define `schema()` with tool name, description, input schema
3. Implement `handle()` with workspace resolution and entitlement checks
4. Register in `Boot::onMcpTools()`
