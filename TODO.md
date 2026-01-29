# TODO - core-content

Production quality improvements for the Content Module.

**Legend:**
- P1: Critical/Security - Must fix immediately
- P2: High priority - Fix soon
- P3: Medium priority - Important improvements
- P4: Low priority - Nice to have
- P5: Nice-to-have - When time permits
- P6+: Future/Backlog - Long-term improvements

---

## P1 - Critical/Security

### SEC-001: Add CSRF protection to webhook endpoints
- **Status:** Open
- **Description:** The webhook endpoint at `POST /api/content/webhooks/{endpoint}` accepts external requests but only validates via HMAC signature. If signature verification is skipped (when no secret is configured), the endpoint is vulnerable.
- **File:** `Controllers/Api/ContentWebhookController.php:205-210`
- **Fix:** Require signature verification always OR add explicit opt-in flag to disable it, with warning logs.
- **Acceptance:** Webhooks without secrets must be explicitly enabled per-endpoint.

### SEC-002: Sanitise HTML content before rendering
- **Status:** Fixed
- **Description:** `ContentItem::getSanitisedContent()` falls back to `strip_tags()` if HTMLPurifier is unavailable. This fallback is insufficient for XSS protection.
- **File:** `Models/ContentItem.php:333-351`
- **Fix:** Always require HTMLPurifier or a robust sanitiser. Add package dependency check in boot.
- **Acceptance:** Content rendering always goes through proper XSS sanitisation.
- **Resolution:** Created `Services/HtmlSanitiser.php` using HTMLPurifier as a required dependency. Added HTMLPurifier to composer.json require. Added boot-time validation that throws RuntimeException if dependency missing. Removed insecure strip_tags() fallback. Added comprehensive XSS prevention tests in `tests/Unit/HtmlSanitiserTest.php`.

### SEC-003: Validate workspace access in MCP handlers
- **Status:** Open
- **Description:** MCP handlers check entitlements but workspace resolution via `orWhere('id', $slug)` could expose content across workspaces if numeric IDs are guessed.
- **File:** `Mcp/Handlers/ContentCreateHandler.php:212-220`, `ContentSearchHandler.php:129-137`
- **Fix:** Add explicit workspace ownership/membership check before returning data.
- **Acceptance:** Users can only access content from workspaces they own or are members of.

### SEC-004: Rate limit preview URL generation
- **Status:** Open
- **Description:** Preview token generation has no rate limiting. An attacker could enumerate valid content IDs by watching response times.
- **File:** `Controllers/ContentPreviewController.php:26-49`
- **Fix:** Add rate limiting to preview generation endpoint.
- **Acceptance:** Preview generation limited to 30/minute per user.

### SEC-005: Validate content_type enum in webhook payloads
- **Status:** Open
- **Description:** Webhook processing accepts arbitrary `content_type` strings from external sources without validation.
- **File:** `Jobs/ProcessContentWebhook.php:288-289`
- **Fix:** Validate against `ContentType` enum before assigning to model.
- **Acceptance:** Invalid content types rejected with clear error message.

---

## P2 - High Priority

### DX-001: Add missing type hints to scope methods
- **Status:** Open
- **Description:** Scope methods like `scopeForWorkspace`, `scopePublished` etc. use `$query` without `Builder` type hint.
- **Files:** `Models/ContentItem.php:147-198`, `Models/ContentBrief.php:181-215`
- **Fix:** Add `\Illuminate\Database\Eloquent\Builder` type hints.
- **Acceptance:** All scope methods have proper return types.

### DX-002: Document search service API response format
- **Status:** Open
- **Description:** `ContentSearchService::formatForApi()` returns a specific structure but it's not documented.
- **File:** `Services/ContentSearchService.php:467-493`
- **Fix:** Add PHPDoc with return type schema or create a Resource class.
- **Acceptance:** API response format documented with example JSON.

### TEST-001: Add integration tests for AI generation pipeline
- **Status:** Open
- **Description:** `AIGatewayService` has no tests. The two-stage Gemini+Claude pipeline is critical but untested.
- **File:** `Services/AIGatewayService.php`
- **Fix:** Add tests with mocked API responses for `generateDraft`, `refineDraft`, `generateAndRefine`.
- **Acceptance:** 80%+ coverage on AIGatewayService with edge case tests.

### TEST-002: Add tests for webhook signature verification
- **Status:** Open
- **Description:** `ContentWebhookEndpoint::verifySignature()` handles multiple formats but isn't fully tested.
- **File:** `Models/ContentWebhookEndpoint.php:204-237`
- **Fix:** Add unit tests for each signature format and grace period behaviour.
- **Acceptance:** Tests cover: sha256= prefix, grace period rotation, empty signature handling.

### PERF-001: Add database index for content search
- **Status:** Open
- **Description:** LIKE-based search on `content_html` has no fulltext index, causing table scans.
- **File:** `Services/ContentSearchService.php:142-162`, `Migrations/0001_01_01_000001_create_content_tables.php`
- **Fix:** Add MySQL fulltext index on title, excerpt, content_markdown columns OR document Meilisearch as required for production.
- **Acceptance:** Search queries under 100ms for 10k+ content items.

### PERF-002: Optimise revision pruning for large datasets
- **Status:** Open
- **Description:** `ContentRevision::pruneAll()` loads all content_item_ids into memory before iterating.
- **File:** `Models/ContentRevision.php:595-609`
- **Fix:** Use `chunk()` or cursor to process in batches.
- **Acceptance:** Pruning handles 100k+ content items without memory issues.

### BUG-001: Fix content_briefs migration schema mismatch
- **Status:** Open
- **Description:** Migration defines `content_briefs` with different columns than model fillable (e.g., `user_id` vs model relationships).
- **File:** `Migrations/0001_01_01_000001_create_content_tables.php:215-238`, `Models/ContentBrief.php:49-75`
- **Fix:** Align migration with actual model usage or add a migration to fix schema.
- **Acceptance:** All ContentBrief columns are used and documented.

### BUG-002: Fix ai_usage migration column naming
- **Status:** Open
- **Description:** Migration creates `feature` column but model uses `purpose`. Creates confusion.
- **File:** `Migrations/0001_01_01_000001_create_content_tables.php:246`, `Models/AIUsage.php:46`
- **Fix:** Add migration to rename column OR update model to use `feature`.
- **Acceptance:** Column name matches model fillable property.

---

## P3 - Medium Priority

### CODE-001: Extract webhook processing logic into service
- **Status:** Open
- **Description:** `ProcessContentWebhook` job contains 500+ lines of business logic that should be in a service.
- **File:** `Jobs/ProcessContentWebhook.php`
- **Fix:** Create `ContentWebhookProcessingService` with methods for each event type.
- **Acceptance:** Job is under 100 lines, delegates to service.

### CODE-002: Create ContentBriefResource for API responses
- **Status:** Open
- **Description:** Controllers manually format brief responses. A Resource class would ensure consistency.
- **File:** `Controllers/Api/ContentBriefController.php` references `ContentBriefResource` which may not exist.
- **Fix:** Create or verify `Resources/ContentBriefResource.php` exists with proper formatting.
- **Acceptance:** All brief API responses use the Resource class.

### CODE-003: Consolidate workspace resolution logic
- **Status:** Open
- **Description:** Three different `resolveWorkspace()` methods exist with similar but not identical logic.
- **Files:** `Controllers/Api/ContentSearchController.php`, `Mcp/Handlers/*`, `Services/ContentRender.php`
- **Fix:** Create trait or shared helper in core-tenant.
- **Acceptance:** Single source of truth for workspace resolution.

### TEST-003: Add tests for revision diff algorithm
- **Status:** Open
- **Description:** `ContentRevision::getDiff()` and LCS algorithm are complex but only lightly tested.
- **File:** `Models/ContentRevision.php:233-509`
- **Fix:** Add unit tests for edge cases: empty content, identical content, very long content.
- **Acceptance:** Diff algorithm has 90%+ coverage with edge cases documented.

### TEST-004: Add webhook retry service tests
- **Status:** Open
- **Description:** `WebhookRetryService` has retry logic with exponential backoff but no tests.
- **File:** `Services/WebhookRetryService.php`
- **Fix:** Add tests for retry scheduling, backoff intervals, exhaustion handling.
- **Acceptance:** Full coverage of retry state transitions.

### FEAT-001: Add content scheduling command
- **Status:** Open
- **Description:** `PublishScheduledContent` command is registered but implementation needs verification.
- **File:** `Console/Commands/PublishScheduledContent.php`
- **Fix:** Verify command works, add scheduler entry documentation.
- **Acceptance:** Scheduled content publishes automatically at the correct time.

### FEAT-002: Add media upload validation
- **Status:** Open
- **Description:** `ContentMediaController` store method should validate file types, sizes, dimensions.
- **File:** `Controllers/Api/ContentMediaController.php`
- **Fix:** Add comprehensive validation rules for media uploads.
- **Acceptance:** Reject files over size limit, invalid types, malformed images.

### FEAT-003: Add bulk operations for content items
- **Status:** Open
- **Description:** No bulk delete, bulk status change, or bulk category assignment endpoints.
- **Files:** API routes, new controller methods needed
- **Fix:** Add bulk endpoints with proper authorisation and rate limiting.
- **Acceptance:** Can bulk-update up to 50 items per request.

---

## P4 - Low Priority

### DX-003: Add IDE helper annotations to models
- **Status:** Open
- **Description:** Models lack `@property` annotations for dynamic attributes like `status_color`.
- **Files:** All models in `Models/`
- **Fix:** Add comprehensive `@property` PHPDoc blocks for all magic attributes.
- **Acceptance:** IDE autocomplete works for all model properties.

### DX-004: Document configuration options
- **Status:** Open
- **Description:** `config.php` has comments but no comprehensive documentation of all options and their effects.
- **File:** `config.php`
- **Fix:** Add CLAUDE.md section or dedicated config docs explaining each option.
- **Acceptance:** Every config option documented with type, default, and example.

### CODE-004: Remove deprecated WordPress-specific code paths
- **Status:** Open
- **Description:** Multiple methods have WordPress-specific handling that may be unused.
- **Files:** `Models/ContentItem.php` (wp_id, wp_guid), various scopes
- **Fix:** Audit usage, add deprecation notices if still needed, or remove.
- **Acceptance:** Clear documentation of what is deprecated vs maintained.

### CODE-005: Standardise error response format
- **Status:** Open
- **Description:** Error responses vary: `['error' => ...]`, `['message' => ...]`, different status codes.
- **Files:** All controllers in `Controllers/Api/`
- **Fix:** Use consistent error format: `{error: string, code: string, message: string}`.
- **Acceptance:** All error responses follow documented schema.

### PERF-003: Add eager loading hints to API responses
- **Status:** Open
- **Description:** Some API responses trigger N+1 queries for related data.
- **Files:** `Controllers/Api/ContentBriefController.php:31-77`
- **Fix:** Add `->with(['workspace', 'contentItem'])` where appropriate.
- **Acceptance:** No N+1 queries in API responses (verified with debugbar).

### TEST-005: Add factory states for all content statuses
- **Status:** Open
- **Description:** Factory states exist but may not cover all status/type combinations.
- **Files:** `Database/Factories/*.php` (if they exist, or in test setup)
- **Fix:** Ensure factories have states for: draft, publish, future, private, pending, trash.
- **Acceptance:** Tests can easily create content in any status.

---

## P5 - Nice to Have

### FEAT-004: Add content versioning comparison UI support
- **Status:** Open
- **Description:** `ContentRevision::getDiff()` returns data but no documented UI integration.
- **File:** `Models/ContentRevision.php`
- **Fix:** Document how to integrate diff data with frontend diff viewer.
- **Acceptance:** Example Livewire component or documentation for diff display.

### FEAT-005: Add webhook event deduplication
- **Status:** Open
- **Description:** Same webhook could be received multiple times (network retry). No dedup.
- **File:** `Jobs/ProcessContentWebhook.php`
- **Fix:** Add deduplication based on payload hash + timestamp window.
- **Acceptance:** Duplicate webhooks within 5 minutes are skipped.

### FEAT-006: Add content analytics tracking
- **Status:** Open
- **Description:** No tracking of content views, engagement, or performance metrics.
- **Files:** New feature needed
- **Fix:** Integrate with core-analytics or add simple view tracking.
- **Acceptance:** Can see view counts and basic metrics per content item.

### CODE-006: Add event dispatching for content lifecycle
- **Status:** Open
- **Description:** Content creation/update/publish doesn't dispatch domain events for other modules.
- **Files:** `Models/ContentItem.php`, `Observers/ContentItemObserver.php`
- **Fix:** Dispatch events like `ContentPublished`, `ContentUpdated` etc.
- **Acceptance:** Other modules can listen for content events.

### DOCS-001: Add API documentation
- **Status:** Open
- **Description:** API endpoints lack OpenAPI/Swagger documentation.
- **Files:** `routes/api.php`
- **Fix:** Add Scribe or OpenAPI annotations for all endpoints.
- **Acceptance:** OpenAPI spec can be generated and used in API clients.

---

## P6 - Future/Backlog

### FEAT-007: Add content workflow/approval system
- **Status:** Backlog
- **Description:** No formal review/approval workflow for content before publishing.
- **Fix:** Add ContentWorkflow model with states and transitions.

### FEAT-008: Add content localisation/translation support
- **Status:** Backlog
- **Description:** No i18n support for multilingual content.
- **Fix:** Add locale field and translation linking to ContentItem.

### FEAT-009: Add content A/B testing
- **Status:** Backlog
- **Description:** No ability to test content variations.
- **Fix:** Add ContentVariant model for headline/content testing.

### PERF-004: Add content caching layer
- **Status:** Backlog
- **Description:** CDN purge exists but no server-side caching strategy documented.
- **Fix:** Document caching strategy, add Redis caching for hot content.

### CODE-007: Extract prompts to database-driven system
- **Status:** Backlog
- **Description:** AI prompts are hardcoded in `AIGatewayService`. Prompts table exists but unused for this.
- **File:** `Services/AIGatewayService.php:226-525`
- **Fix:** Load prompts from database, allow admin editing.

---

## Completed

### SEC-002: HTML sanitisation fallback vulnerability (2026-01-29)
- Created `Services/HtmlSanitiser.php` using HTMLPurifier
- Added `ezyang/htmlpurifier` as required dependency in composer.json
- Updated `ContentItem::getSanitisedContent()` to use the new service
- Added boot-time validation to throw exception if HTMLPurifier is missing
- Removed insecure `strip_tags()` fallback that allowed XSS via event handlers
- Added 30+ unit tests covering XSS attack vectors and safe HTML preservation

---

## Notes

### Dependencies
- Requires `core-php` for events and base infrastructure
- Requires `core-tenant` for workspace and user models
- Requires `ezyang/htmlpurifier` for XSS sanitisation (security-critical)
- Optional: `core-agentic` for AI services (GeminiService, ClaudeService)
- Optional: `core-mcp` for MCP tool registration

### Testing
Run tests with: `composer test` from package root.
Run single test: `./vendor/bin/pest --filter=ContentSearchServiceTest`

### Last Audit
- **Date:** 2026-01-29
- **By:** Claude Code (core-content audit)
- **Files Reviewed:** ~70 PHP files
