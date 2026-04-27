# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package Overview

This is `lthn/php-content`, a Laravel package providing headless CMS functionality for the Core PHP Framework. It handles content management, AI generation, revisions, webhooks, and search.

**Namespace:** `Core\Mod\Content\`
**Entry point:** `Boot.php` (extends `ServiceProvider`, uses event-driven registration)
**Dependencies:** `lthn/php` (core framework), `ezyang/htmlpurifier` (required, security-critical)
**Optional:** `core-tenant` (workspaces/users), `core-agentic` (AI services), `core-mcp` (MCP tool registration)

## Commands

```bash
composer run lint             # Laravel Pint (PSR-12)
composer run test             # Pest tests
./vendor/bin/pint --dirty     # Format changed files only
./vendor/bin/pest --filter=ContentSearch  # Run specific tests
```

## Architecture

### Event-Driven Boot

`Boot.php` registers lazily via event listeners — routes, commands, views, and MCP tools are only loaded when their events fire:

```php
public static array $listens = [
    WebRoutesRegistering::class => 'onWebRoutes',
    ApiRoutesRegistering::class => 'onApiRoutes',
    ConsoleBooting::class => 'onConsole',
    McpToolsRegistering::class => 'onMcpTools',
];
```

### AI Generation Pipeline (Two-Stage)

`AIGatewayService` orchestrates a two-stage content generation pipeline:
1. **Stage 1 (Gemini):** Fast, cost-effective draft generation
2. **Stage 2 (Claude):** Quality refinement and brand voice alignment

Brief workflow: `pending` → `queued` → `generating` → `review` → `published`

### Dual API Authentication

All `/api/content/*` endpoints are registered twice in `routes/api.php`:
- Session auth (`middleware('auth')`)
- API key auth (`middleware(['api.auth', 'api.scope.enforce'])`) — uses `Authorization: Bearer hk_xxx`

Webhook endpoints are public (no auth, signature-verified via HMAC).

### Livewire Component Paths

Livewire components live in `View/Modal/` (not the typical `Livewire/` directory):
- `View/Modal/Web/` — public-facing (Blog, Post, Help, HelpArticle, Preview)
- `View/Modal/Admin/` — admin (WebhookManager, ContentSearch)

Blade templates are in `View/Blade/`, registered with the `content` view namespace.

### Search Backends

`ContentSearchService` supports three backends via `CONTENT_SEARCH_BACKEND`:
- `database` (default) — LIKE queries with relevance scoring (title > slug > excerpt > content)
- `scout_database` — Laravel Scout with database driver
- `meilisearch` — Laravel Scout with Meilisearch

## Conventions

- UK English (colour, organisation, centre, behaviour)
- `declare(strict_types=1);` in all PHP files
- Type hints on all parameters and return types
- Final classes by default unless inheritance is intended
- Pest for testing (not PHPUnit syntax)
- Livewire + Flux Pro for UI components (not vanilla Alpine)
- Font Awesome Pro for icons (not Heroicons)

### Naming

| Type | Convention | Example |
|------|------------|---------|
| Model | Singular PascalCase | `ContentItem` |
| Table | Plural snake_case | `content_items` |
| Controller | `{Model}Controller` | `ContentBriefController` |
| Livewire Page | `{Feature}Page` | `ProductListPage` |
| Livewire Modal | `{Feature}Modal` | `EditProductModal` |

### Don'ts

- Don't create controllers for Livewire pages
- Don't use Heroicons (use Font Awesome Pro)
- Don't use vanilla Alpine components (use Flux Pro)
- Don't use American English spellings

## Configuration (config.php)

Key environment variables:
- `CONTENT_GENERATION_TIMEOUT` — AI generation timeout (default 300s)
- `CONTENT_MAX_REVISIONS` — Revision limit per item (default 50)
- `CONTENT_SEARCH_BACKEND` — Search driver (database, scout_database, meilisearch)
- `CONTENT_CACHE_TTL` — Content cache duration (default 3600s)
- `CONTENT_SEARCH_RATE_LIMIT` — Search rate limit per minute (default 60)

## License

EUPL-1.2 (European Union Public Licence)
