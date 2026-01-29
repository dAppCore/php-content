---
title: Security
description: Security considerations and audit notes for core-content
updated: 2026-01-29
---

# Security

This document covers security considerations, known risks, and recommended mitigations for the `core-content` package.

## Authentication and Authorisation

### API Authentication

The content API supports two authentication methods:

1. **Session Authentication** (`auth` middleware)
   - For browser-based access
   - CSRF protection via Laravel's standard middleware

2. **API Key Authentication** (`api.auth` middleware)
   - For programmatic access
   - Keys prefixed with `hk_`
   - Scope enforcement via `api.scope.enforce` middleware

### Webhook Authentication

Webhooks use HMAC signature verification instead of session/API key auth:

```php
// Signature verification in ContentWebhookEndpoint
public function verifySignature(string $payload, ?string $signature): bool
{
    $expectedSignature = hash_hmac('sha256', $payload, $this->secret);
    return hash_equals($expectedSignature, $signature);
}
```

**Supported signature headers:**
- `X-Signature`
- `X-Hub-Signature-256` (GitHub format)
- `X-WP-Webhook-Signature` (WordPress format)
- `X-Content-Signature`
- `Signature`

### MCP Tool Authentication

MCP tools authenticate via the MCP session context. Workspace access is verified through:
- Workspace resolution (by slug or ID)
- Entitlement checks (`content.mcp_access`, `content.items`)

## Known Security Considerations

### HIGH: HTML Sanitisation Fallback

**Location:** `Models/ContentItem.php:333-351`

**Issue:** The `getSanitisedContent()` method falls back to `strip_tags()` if HTMLPurifier is unavailable. This is insufficient for XSS protection.

```php
// Current fallback (insufficient)
$allowedTags = '<p><br><strong>...<a>...';
return strip_tags($content, $allowedTags);
```

**Risk:** XSS attacks via crafted HTML in content body.

**Mitigation:**
1. Ensure HTMLPurifier is installed in production
2. Add package check in boot to fail loudly if missing
3. Consider using `voku/anti-xss` as a lighter alternative

### HIGH: Webhook Signature Optional

**Location:** `Models/ContentWebhookEndpoint.php:205-210`

**Issue:** When no secret is configured, signature verification is skipped:

```php
if (empty($this->secret)) {
    return true;  // Accepts all requests
}
```

**Risk:** Unauthenticated webhook injection if endpoint has no secret.

**Mitigation:**
1. Require secrets for all production endpoints
2. Add explicit `allow_unsigned` flag if intentional
3. Log warning when unsigned webhooks are accepted
4. Rate limit unsigned endpoints more aggressively

### MEDIUM: Workspace Access in MCP Handlers

**Location:** `Mcp/Handlers/*.php`

**Issue:** Workspace resolution allows lookup by ID:

```php
return Workspace::where('slug', $slug)
    ->orWhere('id', $slug)
    ->first();
```

**Risk:** If an attacker knows a workspace ID, they could potentially access content without being a workspace member.

**Mitigation:**
1. Always verify workspace membership after resolution
2. Use entitlement checks (already present but verify coverage)
3. Consider removing ID-based lookup for MCP

### MEDIUM: Preview Token Enumeration

**Location:** `Controllers/ContentPreviewController.php`

**Issue:** No rate limiting on preview token generation endpoint. An attacker could probe for valid content IDs.

**Mitigation:**
1. Add rate limiting (30/min per user)
2. Use constant-time responses regardless of content existence
3. Consider using UUIDs instead of sequential IDs for preview URLs

### LOW: Webhook Payload Content Types

**Location:** `Jobs/ProcessContentWebhook.php:288-289`

**Issue:** Content type from external webhook is assigned directly:

```php
$contentItem->content_type = ContentType::NATIVE;
```

**Risk:** External systems could potentially inject invalid content types.

**Mitigation:**
1. Validate against `ContentType` enum
2. Default to a safe type if validation fails
3. Log invalid types for monitoring

## Input Validation

### API Request Validation

All API controllers use Laravel's validation:

```php
$validated = $request->validate([
    'q' => 'required|string|min:2|max:500',
    'type' => 'nullable|string|in:post,page',
    'status' => 'nullable',
    // ...
]);
```

**Validated inputs:**
- Search queries (min/max length, string type)
- Content types (enum validation)
- Pagination (min/max values)
- Date ranges (date format, logical order)

### MCP Input Validation

MCP handlers validate via JSON schema:

```php
'inputSchema' => [
    'type' => 'object',
    'properties' => [
        'workspace' => ['type' => 'string'],
        'title' => ['type' => 'string'],
        'type' => ['type' => 'string', 'enum' => ['post', 'page']],
    ],
    'required' => ['workspace', 'title'],
]
```

### Webhook Payload Validation

Webhook payloads undergo:
- JSON decode validation
- Event type normalisation
- Content ID extraction with fallbacks

**Note:** Payload content is stored in JSON column without full validation. Processing logic handles missing/invalid fields gracefully.

## Rate Limiting

### Configured Limiters

| Endpoint | Auth | Unauthenticated | Key |
|----------|------|-----------------|-----|
| AI Generation | 10/min | 2/min | `content-generate` |
| Brief Creation | 30/min | 5/min | `content-briefs` |
| Webhooks | 60/min | 30/min | `content-webhooks` |
| Search | 60/min | 20/min | `content-search` |

### Rate Limit Bypass Risks

1. **IP Spoofing:** Ensure `X-Forwarded-For` handling is configured correctly
2. **Workspace Switching:** Workspace-based limits should use user ID as fallback
3. **API Key Sharing:** Each key should have independent limits

## Data Protection

### Sensitive Data Handling

**Encrypted at rest:**
- `ContentWebhookEndpoint.secret` (cast to `encrypted`)
- `ContentWebhookEndpoint.previous_secret` (cast to `encrypted`)

**Hidden from serialisation:**
- Webhook secrets (via `$hidden` property)

### PII Considerations

Content may contain PII in:
- Article body content
- Author information
- Webhook payloads

**Recommendations:**
1. Implement content retention policies
2. Add GDPR data export/deletion support
3. Log access to PII-containing content

## Webhook Security

### Circuit Breaker

Endpoints automatically disable after 10 consecutive failures:

```php
const MAX_FAILURES = 10;

public function incrementFailureCount(): void
{
    $this->increment('failure_count');
    if ($this->failure_count >= self::MAX_FAILURES) {
        $this->update(['is_enabled' => false]);
    }
}
```

### Secret Rotation

Grace period support for secret rotation:

```php
public function isInGracePeriod(): bool
{
    // Accepts both current and previous secret during grace
}
```

Default grace period: 24 hours

### Allowed Event Types

Endpoints can restrict which event types they accept:

```php
const ALLOWED_TYPES = [
    'wordpress.post_created',
    'wordpress.post_updated',
    // ...
    'generic.payload',
];
```

Wildcard support: `wordpress.*` matches all WordPress events.

## Content Security

### XSS Prevention

1. **Input:** Content stored as-is to preserve formatting
2. **Output:** `getSanitisedContent()` for public rendering
3. **Admin:** Trusted content displayed with proper escaping

**Blade template guidelines:**
- Use `{{ $title }}` for plain text (auto-escaped)
- Use `{!! $content !!}` only for sanitised HTML
- Comments document which fields need which treatment

### SQL Injection

All database queries use:
- Eloquent ORM (parameterised queries)
- Query builder with bindings
- No raw SQL with user input

### CSRF Protection

Web routes include CSRF middleware automatically. API routes exempt (use API key auth).

## Audit Logging

### Logged Events

- Webhook receipt and processing
- AI generation requests and results
- Content creation/update/deletion via MCP
- CDN cache purges
- Authentication failures

### Log Levels

| Event | Level |
|-------|-------|
| Webhook signature failure | WARNING |
| Circuit breaker triggered | WARNING |
| Processing failure | ERROR |
| Successful operations | INFO |
| Skipped operations | DEBUG |

## Recommendations

### Immediate (P1)

1. [ ] Require HTMLPurifier or equivalent in production
2. [ ] Make webhook signature verification mandatory
3. [ ] Add rate limiting to preview generation
4. [ ] Validate content_type from webhook payloads

### Short-term (P2)

1. [ ] Add comprehensive audit logging
2. [ ] Implement content access logging
3. [ ] Add IP allowlisting option for webhooks
4. [ ] Create security-focused test suite

### Long-term (P3+)

1. [ ] Implement content encryption at rest option
2. [ ] Add GDPR compliance features
3. [ ] Create security monitoring dashboard
4. [ ] Add anomaly detection for webhook patterns

## Security Testing

### Manual Testing Checklist

```
[ ] Verify webhook signature rejection with invalid signature
[ ] Test rate limiting enforcement
[ ] Confirm XSS payloads are sanitised
[ ] Verify workspace isolation in API responses
[ ] Test preview token expiration
[ ] Verify CSRF protection on web routes
[ ] Test SQL injection attempts in search
[ ] Verify file type validation on media uploads
```

### Automated Testing

```bash
# Run security-focused tests
./vendor/bin/pest --filter=Security

# Check for common vulnerabilities
./vendor/bin/pint --test  # Code style (includes some security patterns)
```

## Incident Response

### Webhook Compromise

1. Disable affected endpoint
2. Rotate all secrets
3. Review webhook logs for suspicious patterns
4. Regenerate secrets for all endpoints

### Content Injection

1. Identify affected content items
2. Restore from revision history
3. Review webhook source
4. Add additional validation

### API Key Leak

1. Revoke compromised key
2. Review access logs
3. Generate new key with reduced scope
4. Monitor for unauthorised access

## Contact

Security issues should be reported to the security team. Do not create public issues for security vulnerabilities.
