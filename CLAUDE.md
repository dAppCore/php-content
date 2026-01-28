# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package Overview

This is `host-uk/core-content`, a Laravel package providing headless CMS functionality for the Core PHP Framework. It handles content management, AI generation, revisions, webhooks, and search.

**Namespace:** `Core\Mod\Content\`

## Commands

```bash
composer run lint             # Laravel Pint (PSR-12)
composer run test             # Pest tests
./vendor/bin/pint --dirty     # Format changed files only
./vendor/bin/pest --filter=ContentSearch  # Run specific tests
```

## Architecture

### Boot.php (Service Provider)

The entry point extending `ServiceProvider` with event-driven registration:

```php
public static array $listens = [
    WebRoutesRegistering::class => 'onWebRoutes',
    ApiRoutesRegistering::class => 'onApiRoutes',
    ConsoleBooting::class => 'onConsole',
    McpToolsRegistering::class => 'onMcpTools',
];
```

### Package Structure

```
Boot.php              # Service provider + event listeners
config.php            # Package configuration
Models/               # Eloquent: ContentItem, ContentBrief, ContentRevision, etc.
Services/             # Business logic: ContentSearchService, ContentRender, etc.
Controllers/Api/      # REST API controllers
Mcp/Handlers/         # MCP tools for AI agent integration
Jobs/                 # Queue jobs: GenerateContentJob, ProcessContentWebhook
View/Modal/           # Livewire components (Web/ and Admin/)
View/Blade/           # Blade templates
Migrations/           # Database migrations
routes/               # web.php, api.php, console.php
```

### Key Models

| Model | Purpose |
|-------|---------|
| `ContentItem` | Published content with revisions |
| `ContentBrief` | Content generation requests/queue |
| `ContentRevision` | Version history for content items |
| `ContentMedia` | Attached media files |
| `ContentTaxonomy` | Categories and tags |
| `ContentWebhookEndpoint` | External webhook configurations |

### API Routes (routes/api.php)

- `/api/content/briefs` - Content brief CRUD
- `/api/content/generate/*` - AI generation (rate limited)
- `/api/content/media` - Media management
- `/api/content/search` - Full-text search
- `/api/content/webhooks/{endpoint}` - External webhooks (no auth, signature verified)

## Conventions

- UK English (colour, organisation, centre)
- `declare(strict_types=1);` in all PHP files
- Type hints on all parameters and return types
- Pest for testing (not PHPUnit syntax)
- Livewire + Flux Pro for UI components
- Font Awesome Pro for icons (not Heroicons)

## Rate Limiters

Defined in `Boot::configureRateLimiting()`:
- `content-generate` - AI generation (10/min authenticated)
- `content-briefs` - Brief creation (30/min)
- `content-webhooks` - Incoming webhooks (60/min per endpoint)
- `content-search` - Search queries (configurable, default 60/min)

## Configuration (config.php)

Key settings exposed via environment:
- `CONTENT_GENERATION_TIMEOUT` - AI generation timeout
- `CONTENT_MAX_REVISIONS` - Revision limit per item
- `CONTENT_SEARCH_BACKEND` - Search driver (database, scout_database, meilisearch)
- `CONTENT_CACHE_TTL` - Content cache duration

## License

EUPL-1.2 (European Union Public Licence)