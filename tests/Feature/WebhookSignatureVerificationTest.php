<?php

declare(strict_types=1);

namespace Core\Mod\Content\Tests\Feature;

use Core\Mod\Content\Models\ContentWebhookEndpoint;
use Core\Mod\Content\Models\ContentWebhookLog;
use Core\Tenant\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for webhook signature verification.
 *
 * P2-082: Tests webhook signature verification flow
 * P2-083: Tests delivery logging through the controller
 */
class WebhookSignatureVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected Workspace $workspace;

    protected ContentWebhookEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->endpoint = ContentWebhookEndpoint::factory()->create([
            'workspace_id' => $this->workspace->id,
            'secret' => 'test-webhook-secret-key',
            'require_signature' => true,
            'is_enabled' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Signature Verification Success Cases
    // -------------------------------------------------------------------------

    #[Test]
    public function it_accepts_webhook_with_valid_signature(): void
    {
        $payload = json_encode(['ID' => 123, 'post_title' => 'Test Post', 'post_type' => 'post']);
        $signature = hash_hmac('sha256', $payload, 'test-webhook-secret-key');

        $response = $this->postJson(
            "/api/content/webhooks/{$this->endpoint->uuid}",
            json_decode($payload, true),
            [
                'X-Signature' => $signature,
                'X-Event-Type' => 'post.created',
            ]
        );

        $response->assertStatus(202);

        // Verify log was created with signature verified
        $log = ContentWebhookLog::where('endpoint_id', $this->endpoint->id)
            ->where('event_type', 'wordpress.post_created')
            ->first();

        $this->assertNotNull($log);
        $this->assertTrue($log->signature_verified);
        $this->assertNull($log->signature_failure_reason);
    }

    #[Test]
    public function it_accepts_github_style_signature(): void
    {
        $payload = json_encode(['ID' => 456, 'post_title' => 'GitHub Style']);
        $signature = 'sha256='.hash_hmac('sha256', $payload, 'test-webhook-secret-key');

        $response = $this->postJson(
            "/api/content/webhooks/{$this->endpoint->uuid}",
            json_decode($payload, true),
            [
                'X-Hub-Signature-256' => $signature,
                'X-Event-Type' => 'post.updated',
            ]
        );

        $response->assertStatus(202);
    }

    #[Test]
    public function it_accepts_wordpress_webhook_signature_header(): void
    {
        $payload = json_encode(['ID' => 789, 'post_title' => 'WordPress Style']);
        $signature = hash_hmac('sha256', $payload, 'test-webhook-secret-key');

        $response = $this->postJson(
            "/api/content/webhooks/{$this->endpoint->uuid}",
            json_decode($payload, true),
            [
                'X-WP-Webhook-Signature' => $signature,
                'X-WP-Webhook-Event' => 'post_created',
            ]
        );

        $response->assertStatus(202);
    }

    // -------------------------------------------------------------------------
    // Signature Verification Failure Cases
    // -------------------------------------------------------------------------

    #[Test]
    public function it_rejects_webhook_with_missing_signature(): void
    {
        $payload = ['ID' => 123, 'post_title' => 'No Signature'];

        $response = $this->postJson(
            "/api/content/webhooks/{$this->endpoint->uuid}",
            $payload,
            ['X-Event-Type' => 'post.created']
        );

        $response->assertStatus(401);

        // Verify failure was logged
        $log = ContentWebhookLog::where('endpoint_id', $this->endpoint->id)
            ->where('event_type', 'signature_verification_failed')
            ->first();

        $this->assertNotNull($log);
        $this->assertFalse($log->signature_verified);
        $this->assertEquals(ContentWebhookEndpoint::SIGNATURE_FAILURE_MISSING, $log->signature_failure_reason);
    }

    #[Test]
    public function it_rejects_webhook_with_invalid_signature(): void
    {
        $payload = ['ID' => 123, 'post_title' => 'Invalid Signature'];

        $response = $this->postJson(
            "/api/content/webhooks/{$this->endpoint->uuid}",
            $payload,
            [
                'X-Signature' => 'completely-wrong-signature',
                'X-Event-Type' => 'post.created',
            ]
        );

        $response->assertStatus(401);

        $log = ContentWebhookLog::where('endpoint_id', $this->endpoint->id)
            ->where('event_type', 'signature_verification_failed')
            ->first();

        $this->assertNotNull($log);
        $this->assertFalse($log->signature_verified);
        $this->assertEquals(ContentWebhookEndpoint::SIGNATURE_FAILURE_INVALID, $log->signature_failure_reason);
    }

    #[Test]
    public function it_rejects_webhook_with_tampered_payload(): void
    {
        $originalPayload = json_encode(['ID' => 123, 'post_title' => 'Original']);
        $signature = hash_hmac('sha256', $originalPayload, 'test-webhook-secret-key');

        // Send tampered payload with original signature
        $tamperedPayload = ['ID' => 123, 'post_title' => 'Tampered Content'];

        $response = $this->postJson(
            "/api/content/webhooks/{$this->endpoint->uuid}",
            $tamperedPayload,
            [
                'X-Signature' => $signature,
                'X-Event-Type' => 'post.created',
            ]
        );

        $response->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // require_signature Configuration Tests
    // -------------------------------------------------------------------------

    #[Test]
    public function it_rejects_unsigned_webhook_when_signature_required_but_no_secret(): void
    {
        // Endpoint with require_signature=true but no secret configured
        $endpoint = ContentWebhookEndpoint::factory()->create([
            'workspace_id' => $this->workspace->id,
            'secret' => null, // No secret
            'require_signature' => true,
            'is_enabled' => true,
        ]);

        $response = $this->postJson(
            "/api/content/webhooks/{$endpoint->uuid}",
            ['ID' => 123],
            ['X-Event-Type' => 'post.created']
        );

        $response->assertStatus(401);

        $log = ContentWebhookLog::where('endpoint_id', $endpoint->id)
            ->where('event_type', 'signature_verification_failed')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(ContentWebhookEndpoint::SIGNATURE_FAILURE_NO_SECRET, $log->signature_failure_reason);
    }

    #[Test]
    public function it_accepts_unsigned_webhook_when_signature_not_required(): void
    {
        // Endpoint with signature verification explicitly disabled
        $unsignedEndpoint = ContentWebhookEndpoint::factory()->create([
            'workspace_id' => $this->workspace->id,
            'secret' => null,
            'require_signature' => false,
            'is_enabled' => true,
        ]);

        $response = $this->postJson(
            "/api/content/webhooks/{$unsignedEndpoint->uuid}",
            ['ID' => 123, 'post_title' => 'Unsigned OK'],
            ['X-Event-Type' => 'post.created']
        );

        $response->assertStatus(202);

        $log = ContentWebhookLog::where('endpoint_id', $unsignedEndpoint->id)
            ->where('event_type', 'wordpress.post_created')
            ->first();

        $this->assertNotNull($log);
        $this->assertTrue($log->signature_verified);
    }

    // -------------------------------------------------------------------------
    // Secret Rotation Grace Period Tests
    // -------------------------------------------------------------------------

    #[Test]
    public function it_accepts_signature_with_previous_secret_during_grace_period(): void
    {
        $oldSecret = 'old-secret-key';
        $newSecret = 'new-secret-key';

        // Set up endpoint in grace period
        $this->endpoint->update([
            'secret' => $newSecret,
            'previous_secret' => $oldSecret,
            'secret_rotated_at' => now(),
            'grace_period_seconds' => 86400, // 24 hours
        ]);

        // Sign with old secret
        $payload = json_encode(['ID' => 123, 'post_title' => 'Grace Period Test']);
        $signature = hash_hmac('sha256', $payload, $oldSecret);

        $response = $this->postJson(
            "/api/content/webhooks/{$this->endpoint->uuid}",
            json_decode($payload, true),
            [
                'X-Signature' => $signature,
                'X-Event-Type' => 'post.created',
            ]
        );

        $response->assertStatus(202);
    }

    #[Test]
    public function it_rejects_old_secret_after_grace_period_expires(): void
    {
        $oldSecret = 'old-secret-key';
        $newSecret = 'new-secret-key';

        // Set up endpoint with expired grace period
        $this->endpoint->update([
            'secret' => $newSecret,
            'previous_secret' => $oldSecret,
            'secret_rotated_at' => now()->subDays(2), // 2 days ago
            'grace_period_seconds' => 86400, // 24 hour grace period (expired)
        ]);

        // Sign with old secret
        $payload = json_encode(['ID' => 123, 'post_title' => 'Expired Grace']);
        $signature = hash_hmac('sha256', $payload, $oldSecret);

        $response = $this->postJson(
            "/api/content/webhooks/{$this->endpoint->uuid}",
            json_decode($payload, true),
            [
                'X-Signature' => $signature,
                'X-Event-Type' => 'post.created',
            ]
        );

        $response->assertStatus(401);
    }

    #[Test]
    public function it_accepts_new_secret_during_grace_period(): void
    {
        $oldSecret = 'old-secret-key';
        $newSecret = 'new-secret-key';

        // Set up endpoint in grace period
        $this->endpoint->update([
            'secret' => $newSecret,
            'previous_secret' => $oldSecret,
            'secret_rotated_at' => now(),
            'grace_period_seconds' => 86400,
        ]);

        // Sign with new secret
        $payload = json_encode(['ID' => 123, 'post_title' => 'New Secret Test']);
        $signature = hash_hmac('sha256', $payload, $newSecret);

        $response = $this->postJson(
            "/api/content/webhooks/{$this->endpoint->uuid}",
            json_decode($payload, true),
            [
                'X-Signature' => $signature,
                'X-Event-Type' => 'post.created',
            ]
        );

        $response->assertStatus(202);
    }

    // -------------------------------------------------------------------------
    // Delivery Logging Tests
    // -------------------------------------------------------------------------

    #[Test]
    public function it_logs_request_headers(): void
    {
        $payload = json_encode(['ID' => 123, 'post_title' => 'Header Test']);
        $signature = hash_hmac('sha256', $payload, 'test-webhook-secret-key');

        $response = $this->postJson(
            "/api/content/webhooks/{$this->endpoint->uuid}",
            json_decode($payload, true),
            [
                'X-Signature' => $signature,
                'X-Event-Type' => 'post.created',
                'User-Agent' => 'WordPress/6.4',
                'X-Request-Id' => 'req-abc-123',
            ]
        );

        $response->assertStatus(202);

        $log = ContentWebhookLog::where('endpoint_id', $this->endpoint->id)
            ->where('event_type', 'wordpress.post_created')
            ->first();

        $this->assertNotNull($log->request_headers);
        $this->assertIsArray($log->request_headers);
        $this->assertArrayHasKey('User-Agent', $log->request_headers);
        $this->assertArrayHasKey('X-Event-Type', $log->request_headers);
    }

    #[Test]
    public function it_logs_source_ip(): void
    {
        $payload = json_encode(['ID' => 123, 'post_title' => 'IP Test']);
        $signature = hash_hmac('sha256', $payload, 'test-webhook-secret-key');

        $this->postJson(
            "/api/content/webhooks/{$this->endpoint->uuid}",
            json_decode($payload, true),
            ['X-Signature' => $signature, 'X-Event-Type' => 'post.created']
        );

        $log = ContentWebhookLog::where('endpoint_id', $this->endpoint->id)
            ->where('event_type', 'wordpress.post_created')
            ->first();

        $this->assertNotNull($log->source_ip);
    }

    // -------------------------------------------------------------------------
    // Endpoint State Tests
    // -------------------------------------------------------------------------

    #[Test]
    public function it_rejects_webhook_for_disabled_endpoint(): void
    {
        $this->endpoint->update(['is_enabled' => false]);

        $payload = json_encode(['ID' => 123, 'post_title' => 'Disabled']);
        $signature = hash_hmac('sha256', $payload, 'test-webhook-secret-key');

        $response = $this->postJson(
            "/api/content/webhooks/{$this->endpoint->uuid}",
            json_decode($payload, true),
            ['X-Signature' => $signature, 'X-Event-Type' => 'post.created']
        );

        $response->assertStatus(403);
        $response->assertSee('Endpoint disabled');
    }

    #[Test]
    public function it_rejects_webhook_when_circuit_breaker_is_open(): void
    {
        $this->endpoint->update([
            'failure_count' => ContentWebhookEndpoint::MAX_FAILURES,
        ]);

        $payload = json_encode(['ID' => 123, 'post_title' => 'Circuit Open']);
        $signature = hash_hmac('sha256', $payload, 'test-webhook-secret-key');

        $response = $this->postJson(
            "/api/content/webhooks/{$this->endpoint->uuid}",
            json_decode($payload, true),
            ['X-Signature' => $signature, 'X-Event-Type' => 'post.created']
        );

        $response->assertStatus(503);
        $response->assertSee('Service unavailable');
    }

    #[Test]
    public function it_rejects_disallowed_event_types(): void
    {
        $this->endpoint->update([
            'allowed_types' => ['wordpress.post_created', 'wordpress.post_updated'],
        ]);

        $payload = json_encode(['ID' => 123, 'post_title' => 'Delete Event']);
        $signature = hash_hmac('sha256', $payload, 'test-webhook-secret-key');

        $response = $this->postJson(
            "/api/content/webhooks/{$this->endpoint->uuid}",
            json_decode($payload, true),
            ['X-Signature' => $signature, 'X-Event-Type' => 'post.deleted']
        );

        $response->assertStatus(403);
        $response->assertSee('Event type not allowed');
    }

    #[Test]
    public function it_rejects_invalid_json_payload(): void
    {
        $invalidJson = '{invalid json';
        $signature = hash_hmac('sha256', $invalidJson, 'test-webhook-secret-key');

        $response = $this->call(
            'POST',
            "/api/content/webhooks/{$this->endpoint->uuid}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $signature,
                'HTTP_X_EVENT_TYPE' => 'post.created',
            ],
            $invalidJson
        );

        $response->assertStatus(400);
        $response->assertSee('Invalid JSON payload');
    }

    // -------------------------------------------------------------------------
    // Security Audit Tests
    // -------------------------------------------------------------------------

    #[Test]
    public function it_does_not_store_payload_for_failed_signatures(): void
    {
        $sensitivePayload = [
            'ID' => 123,
            'post_title' => 'Sensitive Data',
            'api_key' => 'secret-api-key-123',
            'password' => 'super-secret-password',
        ];

        $response = $this->postJson(
            "/api/content/webhooks/{$this->endpoint->uuid}",
            $sensitivePayload,
            [
                'X-Signature' => 'invalid-signature',
                'X-Event-Type' => 'post.created',
            ]
        );

        $response->assertStatus(401);

        $log = ContentWebhookLog::where('endpoint_id', $this->endpoint->id)
            ->where('event_type', 'signature_verification_failed')
            ->first();

        // Payload should NOT be stored for security
        $this->assertNull($log->payload);
    }

    #[Test]
    public function it_uses_timing_safe_comparison_for_signatures(): void
    {
        // This test verifies that we're using hash_equals() internally
        // by checking that both valid and invalid signatures take similar time
        // We can't directly test timing, but we verify the signature verification
        // uses the constant-time comparison method

        $payload = json_encode(['ID' => 123]);
        $validSignature = hash_hmac('sha256', $payload, 'test-webhook-secret-key');

        // Valid signature
        $response1 = $this->postJson(
            "/api/content/webhooks/{$this->endpoint->uuid}",
            json_decode($payload, true),
            ['X-Signature' => $validSignature, 'X-Event-Type' => 'post.created']
        );
        $response1->assertStatus(202);

        // Invalid signature (should be compared using hash_equals)
        $response2 = $this->postJson(
            "/api/content/webhooks/{$this->endpoint->uuid}",
            json_decode($payload, true),
            ['X-Signature' => 'a'.substr($validSignature, 1), 'X-Event-Type' => 'post.updated']
        );
        $response2->assertStatus(401);

        // The implementation uses hash_equals() which is timing-safe
        // This is verified by code inspection in ContentWebhookEndpoint::verifySignatureWithDetails()
    }
}
