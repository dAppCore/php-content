# Content Module Review

**Updated:** 2026-01-21 - All recommended improvements implemented

## Overview

The Content module manages the content system for Host UK, providing:

1. **Native Content Management** - ContentItem, ContentAuthor, ContentMedia, ContentTaxonomy, ContentRevision models for storing and managing content natively
2. **AI Content Generation** - Two-stage pipeline using Gemini (draft) + Claude (refinement) via ContentBrief and AIGatewayService
3. **WordPress Import** - One-time import from WordPress sites via REST API (ContentImportWordPress command)
4. **Public Rendering** - Satellite pages for blog/help content via Livewire components
5. **Content Processing** - HTML cleaning and JSON block parsing for headless rendering

The module is transitioning away from WordPress sync (deprecated) to a fully native content system with AI-assisted content generation.

## Production Readiness Score: 92/100 (was 85/100 - All recommended improvements implemented 2026-01-21)

## Critical Issues (Must Fix)

- [x] **Missing database migrations for core tables** - FIXED: Created `2026_01_14_000001_create_content_core_tables.php` migration documenting all 7 content tables with `Schema::hasTable()` guards.

- [x] **ContentBrief model missing factory** - FIXED: Created `ContentBriefFactory.php` with states for status, content types, and priorities.

- [x] **AIUsage model missing factory** - FIXED: Created `AIUsageFactory.php` with states for providers, purposes, and usage amounts.

- [x] **Content rendering XSS vulnerability** - FIXED: All blade templates now use `{{ }}` for titles. Added `getSanitisedContent()` method using HTMLPurifier for body content.

- [x] **Waitlist stored in cache only** - FIXED: `ContentRender` now uses `WaitlistEntry` model from Tenant module for persistent database storage.

## Recommended Improvements

- [x] **Add rate limiting to API endpoints** - FIXED: Rate limiting added to `/api/v1/content/generate/*` endpoints.

- [x] **Add validation for content_type in ContentBrief** - FIXED: BriefContentType enum added for type safety.

- [x] **ContentProcessingService error handling** - FIXED: DOM parsing errors now logged for debugging.

- [x] **Cache key collision potential** - FIXED: Cache key sanitisation added to ContentRender to handle special characters in slugs.

- [x] **Add indexes to ContentBrief table** - FIXED: Index added for `scheduled_for` column.

- [x] **Job timeout configuration** - FIXED: `GenerateContentJob` timeout now configurable for different content types.

- [x] **AIGatewayService constructor signature** - FIXED: Refactored to read config() fresh in getGemini()/getClaude() methods. Constructor now only stores optional overrides. Added resetServices() method for runtime config changes. Verified implementation shows clean separation of override vs config-based configuration.

- [x] **Help component queries pages not help articles** - FIXED: Help.php now uses `helpArticles` scope for proper content filtering.

- [x] **ContentRevision lacks pruning mechanism** - FIXED: Revision pruning command added with configurable retention policy.

- [x] **Deprecated commands still registered** - VERIFIED: ContentSync and ContentPublish already have proper deprecation warnings via trigger_error(E_USER_DEPRECATED) and console output.

## Missing Features (Future)

- [x] **Content scheduling system** - FIXED: Created `PublishScheduledContent` command (`content:publish-scheduled`) that uses `readyToPublish()` scope. Supports `--dry-run` and `--limit` options. Publishes 'future' status items whose `publish_at` has passed. Scheduled to run every minute via routes/console.php.

- [ ] **Media upload endpoint** - No API endpoint for uploading media. Only WordPress import creates ContentMedia records.

- [ ] **Content search** - No full-text search capability. Consider adding a search endpoint with elasticsearch/meilisearch integration.

- [ ] **Revision comparison/diff view** - `ContentRevision::getDiffSummary()` exists but returns boolean changed flags, not actual diff content.

- [ ] **Webhook handler** - ContentWebhookLog model exists but no webhook endpoint or processing job to receive/handle webhooks.

- [ ] **CDN cache purge integration** - `getCdnUrlsForPurgeAttribute()` generates purge URLs but no integration to actually call Bunny CDN purge API.

- [ ] **Content versioning API** - No API endpoints for listing/restoring revisions.

- [ ] **MCP tools** - `onMcpTools()` is stubbed out with commented examples. MCP integration incomplete.

- [ ] **Bulk operations** - No bulk publish, bulk delete, or bulk status change operations.

- [ ] **Content preview** - No preview endpoint for draft content before publishing.

## Test Coverage Assessment

**Current Coverage: Good for models, sparse for services**

Existing tests:
- `ContentRenderTest.php` - Tests ContentRender service, waitlist, workspace resolution (7 tests)
- `FactoriesTest.php` - Tests all model factories and computed properties (31 tests)
- `ContentManagerTest.php` - Tests model relationships, scopes, and queries (29 tests)

Missing test coverage:
- [ ] No tests for `AIGatewayService` - critical service, needs mocked tests
- [ ] No tests for `ContentProcessingService` - HTML parsing/cleaning needs test cases
- [ ] No tests for API controllers (`ContentBriefController`, `GenerationController`)
- [ ] No tests for `GenerateContentJob` queue job
- [ ] No tests for `ContentImportWordPress` command
- [ ] No integration tests for the full AI generation pipeline
- [ ] No tests for `ContentBrief` model (has no factory)
- [ ] No tests for `ContentRevision::createFromContentItem()` or `restoreToContentItem()`

## Security Concerns

1. **XSS in blade templates** - FIXED: Raw HTML output now uses sanitised content methods.

2. **No CSRF on subscribe endpoint** - The `WorkspaceRouter` handles POST /subscribe but the route bypasses middleware that would normally apply CSRF. Verify CSRF is checked.

3. **API authentication relies on middleware** - Routes use `auth` and `api.auth` middleware. Verify these are properly configured and cannot be bypassed.

4. **WordPress import stores arbitrary content** - `ContentImportWordPress` imports HTML content as-is. Could contain malicious scripts. Should sanitise on import.

5. **No authorisation checks on ContentBrief** - Controllers check workspace access but no policy/gate for fine-grained permissions (e.g., only editors can approve, only admins can delete).

6. **AI API keys in memory** - `AIGatewayService` stores API keys as instance properties. Not a major concern but consider using Laravel's encrypted config if storing in database.

## Notes

1. **Architecture clarity** - Good separation of concerns: Models handle data, Services handle business logic, Controllers handle HTTP, Jobs handle async. Clean module structure.

2. **WordPress transition** - The module is mid-transition from WordPress to native content. Some WordPress-specific code remains (ContentType::WORDPRESS, wp_id fields, import command). Deprecation notices are good.

3. **AI pipeline design** - The two-stage Gemini->Claude pipeline is well-designed for cost optimisation. Good use of usage tracking.

4. **ContentType enum** - Well-implemented enum with utility methods (label, color, icon, isNative). Good pattern.

5. **Content processing** - `ContentProcessingService` is comprehensive with proper DOM handling. Could be extracted to a shared package.

6. **Livewire components** - Follow consistent pattern. Consider extracting common workspace resolution logic to a trait.

7. **Hardcoded paths** - `ContentValidate` and `ContentGenerate` commands reference `base_path('doc/phase42/drafts')`. Should be configurable.

8. **Dependencies on other modules** - Module depends on:
   - `Mod\Tenant` (Workspace, User)
   - `Mod\Agentic` (ContentService, Prompt, ClaudeService, GeminiService, AgenticResponse)
   - `Mod\Api` (HasApiResponses, ResolvesWorkspace)
   - `Core` (Controller, events, HasSeoMetadata)

9. **Model pricing outdated** - `AIUsage::$pricing` will need regular updates as AI providers change pricing.
