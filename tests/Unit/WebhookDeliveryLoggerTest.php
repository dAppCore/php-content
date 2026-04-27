<?php

declare(strict_types=1);

namespace Core\Mod\Content\Tests\Unit;

use Core\Mod\Content\Models\ContentWebhookEndpoint;
use Core\Mod\Content\Models\ContentWebhookLog;
use Core\Mod\Content\Services\WebhookDeliveryLogger;
use Core\Tenant\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for WebhookDeliveryLogger service.
 *
 * P2-082: Tests for signature verification logging
 * P2-083: Tests for comprehensive delivery logging
 */
class WebhookDeliveryLoggerTest extends TestCase
{
    protected WebhookDeliveryLogger $logger;

    protected Workspace $workspace;

    protected ContentWebhookEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new WebhookDeliveryLogger;
        $this->workspace = Workspace::factory()->create();
        $this->endpoint = ContentWebhookEndpoint::factory()->create([
            'workspace_id' => $this->workspace->id,
            'secret' => 'test-secret-key',
            'require_signature' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // P2-083: Delivery Logging Tests
    // -------------------------------------------------------------------------

    #[Test]
    public function it_logs_successful_delivery_with_full_details(): void
    {
        $webhookLog = ContentWebhookLog::factory()->create([
            'workspace_id' => $this->workspace->id,
            'endpoint_id' => $this->endpoint->id,
            'status' => 'pending',
        ]);

        $this->logger->logSuccess(
            $webhookLog,
            durationMs: 150,
            responseCode: 200,
            responseBody: '{"status": "ok"}'
        );

        $webhookLog->refresh();

        $this->assertEquals('completed', $webhookLog->status);
        $this->assertEquals(150, $webhookLog->processing_duration_ms);
        $this->assertEquals(200, $webhookLog->response_code);
        $this->assertEquals('{"status": "ok"}', $webhookLog->response_body);
        $this->assertNotNull($webhookLog->processed_at);
        $this->assertNull($webhookLog->error_message);
    }

    #[Test]
    public function it_logs_failed_delivery_with_full_details(): void
    {
        $webhookLog = ContentWebhookLog::factory()->create([
            'workspace_id' => $this->workspace->id,
            'endpoint_id' => $this->endpoint->id,
            'status' => 'pending',
        ]);

        $this->logger->logFailure(
            $webhookLog,
            error: 'Connection timeout',
            durationMs: 30000,
            responseCode: 504,
            responseBody: 'Gateway Timeout'
        );

        $webhookLog->refresh();

        $this->assertEquals('failed', $webhookLog->status);
        $this->assertEquals(30000, $webhookLog->processing_duration_ms);
        $this->assertEquals(504, $webhookLog->response_code);
        $this->assertEquals('Gateway Timeout', $webhookLog->response_body);
        $this->assertStringContainsString('Connection timeout', $webhookLog->error_message);
        $this->assertNotNull($webhookLog->processed_at);
    }

    #[Test]
    public function it_truncates_long_response_bodies(): void
    {
        $webhookLog = ContentWebhookLog::factory()->create([
            'workspace_id' => $this->workspace->id,
            'endpoint_id' => $this->endpoint->id,
            'status' => 'pending',
        ]);

        // Create a very long response body (> 10000 chars)
        $longBody = str_repeat('x', 15000);

        $this->logger->logSuccess(
            $webhookLog,
            durationMs: 100,
            responseCode: 200,
            responseBody: $longBody
        );

        $webhookLog->refresh();

        // Response body should be truncated to 10000 chars
        $this->assertLessThanOrEqual(10000, strlen($webhookLog->response_body ?? ''));
    }

    #[Test]
    public function it_creates_delivery_log_with_all_fields(): void
    {
        $request = Request::create('/api/content/webhooks/test', 'POST', [], [], [], [
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_USER_AGENT' => 'WordPress/6.4',
            'HTTP_X_EVENT_TYPE' => 'post.created',
        ], '{"ID": 123, "post_type": "post"}');

        $payload = ['ID' => 123, 'post_type' => 'post', 'post_title' => 'Test'];
        $verificationResult = ['verified' => true, 'reason' => 'verified'];

        $log = $this->logger->createDeliveryLog(
            $request,
            $this->endpoint,
            $payload,
            'wordpress.post_created',
            $verificationResult
        );

        $this->assertEquals($this->workspace->id, $log->workspace_id);
        $this->assertEquals($this->endpoint->id, $log->endpoint_id);
        $this->assertEquals('wordpress.post_created', $log->event_type);
        $this->assertEquals(123, $log->wp_id);
        $this->assertEquals('post', $log->content_type);
        $this->assertEquals($payload, $log->payload);
        $this->assertEquals('pending', $log->status);
        $this->assertTrue($log->signature_verified);
        $this->assertNull($log->signature_failure_reason);
        $this->assertIsArray($log->request_headers);
    }

    #[Test]
    public function it_extracts_safe_headers_only(): void
    {
        $request = Request::create('/api/content/webhooks/test', 'POST', [], [], [], [
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_USER_AGENT' => 'WordPress/6.4',
            'HTTP_X_SIGNATURE' => 'sha256=secret_signature_value',
            'HTTP_AUTHORIZATION' => 'Bearer secret_token',
            'HTTP_X_API_KEY' => 'super_secret_key',
            'HTTP_X_EVENT_TYPE' => 'post.created',
        ]);

        $headers = $this->logger->extractSafeHeaders($request);

        // Safe headers should be present
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertArrayHasKey('User-Agent', $headers);
        $this->assertArrayHasKey('X-Event-Type', $headers);

        // Sensitive headers should NOT be present
        $this->assertArrayNotHasKey('X-Signature', $headers);
        $this->assertArrayNotHasKey('Authorization', $headers);
        $this->assertArrayNotHasKey('X-Api-Key', $headers);
    }

    #[Test]
    public function it_records_processing_metrics(): void
    {
        $webhookLog = ContentWebhookLog::factory()->create([
            'workspace_id' => $this->workspace->id,
            'endpoint_id' => $this->endpoint->id,
            'status' => 'processing',
        ]);

        $this->logger->recordProcessingMetrics(
            $webhookLog,
            durationMs: 250,
            result: ['action' => 'created', 'content_item_id' => 456]
        );

        $webhookLog->refresh();

        $this->assertEquals(250, $webhookLog->processing_duration_ms);
    }

    #[Test]
    public function it_calculates_delivery_statistics(): void
    {
        // Create some webhook logs with various statuses
        ContentWebhookLog::factory()->count(5)->create([
            'workspace_id' => $this->workspace->id,
            'endpoint_id' => $this->endpoint->id,
            'status' => 'completed',
            'signature_verified' => true,
            'processing_duration_ms' => 100,
        ]);

        ContentWebhookLog::factory()->count(2)->create([
            'workspace_id' => $this->workspace->id,
            'endpoint_id' => $this->endpoint->id,
            'status' => 'failed',
            'signature_verified' => true,
            'processing_duration_ms' => 200,
        ]);

        ContentWebhookLog::factory()->count(1)->create([
            'workspace_id' => $this->workspace->id,
            'endpoint_id' => $this->endpoint->id,
            'status' => 'failed',
            'signature_verified' => false,
            'signature_failure_reason' => 'signature_invalid',
        ]);

        ContentWebhookLog::factory()->count(2)->create([
            'workspace_id' => $this->workspace->id,
            'endpoint_id' => $this->endpoint->id,
            'status' => 'pending',
            'signature_verified' => true,
        ]);

        $stats = $this->logger->getDeliveryStats($this->workspace->id);

        $this->assertEquals(10, $stats['total']);
        $this->assertEquals(5, $stats['successful']);
        $this->assertEquals(3, $stats['failed']);
        $this->assertEquals(2, $stats['pending']);
        $this->assertEquals(1, $stats['signature_failures']);
        $this->assertEquals(50.0, $stats['success_rate']);
        $this->assertNotNull($stats['avg_duration_ms']);
    }

    #[Test]
    public function it_calculates_statistics_for_specific_endpoint(): void
    {
        // Create another endpoint
        $otherEndpoint = ContentWebhookEndpoint::factory()->create([
            'workspace_id' => $this->workspace->id,
        ]);

        ContentWebhookLog::factory()->count(3)->create([
            'workspace_id' => $this->workspace->id,
            'endpoint_id' => $this->endpoint->id,
            'status' => 'completed',
        ]);

        ContentWebhookLog::factory()->count(2)->create([
            'workspace_id' => $this->workspace->id,
            'endpoint_id' => $otherEndpoint->id,
            'status' => 'completed',
        ]);

        $stats = $this->logger->getDeliveryStats(
            workspaceId: $this->workspace->id,
            endpointId: $this->endpoint->id
        );

        $this->assertEquals(3, $stats['total']);
    }

    // -------------------------------------------------------------------------
    // P2-082: Signature Verification Logging Tests
    // -------------------------------------------------------------------------

    #[Test]
    public function it_logs_signature_verification_failure(): void
    {
        Log::spy();

        $request = Request::create('/api/content/webhooks/test', 'POST', [], [], [], [
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => 'invalid_signature',
        ]);

        $log = $this->logger->logSignatureFailure(
            $request,
            $this->endpoint,
            ContentWebhookEndpoint::SIGNATURE_FAILURE_INVALID
        );

        $this->assertEquals($this->workspace->id, $log->workspace_id);
        $this->assertEquals($this->endpoint->id, $log->endpoint_id);
        $this->assertEquals('signature_verification_failed', $log->event_type);
        $this->assertEquals('failed', $log->status);
        $this->assertFalse($log->signature_verified);
        $this->assertEquals(ContentWebhookEndpoint::SIGNATURE_FAILURE_INVALID, $log->signature_failure_reason);
        $this->assertNull($log->payload); // Payload should not be stored for failed signatures
        $this->assertNotNull($log->processed_at);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'signature verification failed'));
    }

    #[Test]
    public function it_logs_signature_success(): void
    {
        Log::spy();

        $webhookLog = ContentWebhookLog::factory()->create([
            'workspace_id' => $this->workspace->id,
            'endpoint_id' => $this->endpoint->id,
            'signature_verified' => null,
        ]);

        $this->logger->logSignatureSuccess(
            $webhookLog,
            ContentWebhookEndpoint::SIGNATURE_SUCCESS
        );

        $webhookLog->refresh();

        $this->assertTrue($webhookLog->signature_verified);
        $this->assertNull($webhookLog->signature_failure_reason);
    }

    #[Test]
    public function it_logs_signature_success_during_grace_period(): void
    {
        $webhookLog = ContentWebhookLog::factory()->create([
            'workspace_id' => $this->workspace->id,
            'endpoint_id' => $this->endpoint->id,
            'signature_verified' => null,
        ]);

        $this->logger->logSignatureSuccess(
            $webhookLog,
            ContentWebhookEndpoint::SIGNATURE_SUCCESS_GRACE
        );

        $webhookLog->refresh();

        $this->assertTrue($webhookLog->signature_verified);
    }

    #[Test]
    public function it_logs_when_signature_not_required(): void
    {
        Log::spy();

        $unsignedEndpoint = ContentWebhookEndpoint::factory()->create([
            'workspace_id' => $this->workspace->id,
            'secret' => null,
            'require_signature' => false,
        ]);

        $request = Request::create('/api/content/webhooks/test', 'POST');

        $this->logger->logSignatureNotRequired($request, $unsignedEndpoint);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'without signature verification'));
    }

    #[Test]
    public function it_retrieves_recent_signature_failures(): void
    {
        // Create some signature failures
        ContentWebhookLog::factory()->count(3)->create([
            'workspace_id' => $this->workspace->id,
            'endpoint_id' => $this->endpoint->id,
            'signature_verified' => false,
            'signature_failure_reason' => 'signature_invalid',
        ]);

        // Create some successful verifications
        ContentWebhookLog::factory()->count(2)->create([
            'workspace_id' => $this->workspace->id,
            'endpoint_id' => $this->endpoint->id,
            'signature_verified' => true,
        ]);

        $failures = $this->logger->getRecentSignatureFailures($this->workspace->id);

        $this->assertCount(3, $failures);
        foreach ($failures as $failure) {
            $this->assertFalse($failure->signature_verified);
        }
    }

    #[Test]
    public function it_limits_recent_signature_failures(): void
    {
        ContentWebhookLog::factory()->count(10)->create([
            'workspace_id' => $this->workspace->id,
            'endpoint_id' => $this->endpoint->id,
            'signature_verified' => false,
        ]);

        $failures = $this->logger->getRecentSignatureFailures(
            workspaceId: $this->workspace->id,
            limit: 5
        );

        $this->assertCount(5, $failures);
    }

    #[Test]
    public function it_creates_delivery_log_with_failed_verification(): void
    {
        $request = Request::create('/api/content/webhooks/test', 'POST');

        $payload = ['ID' => 123, 'post_type' => 'post'];
        $verificationResult = [
            'verified' => false,
            'reason' => ContentWebhookEndpoint::SIGNATURE_FAILURE_MISSING,
        ];

        $log = $this->logger->createDeliveryLog(
            $request,
            $this->endpoint,
            $payload,
            'wordpress.post_created',
            $verificationResult
        );

        $this->assertFalse($log->signature_verified);
        $this->assertEquals(ContentWebhookEndpoint::SIGNATURE_FAILURE_MISSING, $log->signature_failure_reason);
    }

    // -------------------------------------------------------------------------
    // Content ID/Type Extraction Tests
    // -------------------------------------------------------------------------

    #[Test]
    public function it_extracts_content_id_from_various_payload_formats(): void
    {
        $request = Request::create('/api/content/webhooks/test', 'POST');
        $verificationResult = ['verified' => true, 'reason' => 'verified'];

        // WordPress format with ID
        $log1 = $this->logger->createDeliveryLog(
            $request,
            $this->endpoint,
            ['ID' => 123],
            'wordpress.post_created',
            $verificationResult
        );
        $this->assertEquals(123, $log1->wp_id);

        // WordPress format with post_id
        $log2 = $this->logger->createDeliveryLog(
            $request,
            $this->endpoint,
            ['post_id' => 456],
            'wordpress.post_created',
            $verificationResult
        );
        $this->assertEquals(456, $log2->wp_id);

        // Generic CMS format with nested data
        $log3 = $this->logger->createDeliveryLog(
            $request,
            $this->endpoint,
            ['data' => ['id' => 789]],
            'cms.content_created',
            $verificationResult
        );
        $this->assertEquals(789, $log3->wp_id);
    }

    #[Test]
    public function it_extracts_content_type_from_various_payload_formats(): void
    {
        $request = Request::create('/api/content/webhooks/test', 'POST');
        $verificationResult = ['verified' => true, 'reason' => 'verified'];

        // WordPress format
        $log1 = $this->logger->createDeliveryLog(
            $request,
            $this->endpoint,
            ['ID' => 1, 'post_type' => 'page'],
            'wordpress.post_created',
            $verificationResult
        );
        $this->assertEquals('page', $log1->content_type);

        // Generic format
        $log2 = $this->logger->createDeliveryLog(
            $request,
            $this->endpoint,
            ['id' => 1, 'content_type' => 'article'],
            'cms.content_created',
            $verificationResult
        );
        $this->assertEquals('article', $log2->content_type);

        // Nested format
        $log3 = $this->logger->createDeliveryLog(
            $request,
            $this->endpoint,
            ['data' => ['id' => 1, 'type' => 'blog']],
            'cms.content_created',
            $verificationResult
        );
        $this->assertEquals('blog', $log3->content_type);
    }

    // -------------------------------------------------------------------------
    // Edge Cases
    // -------------------------------------------------------------------------

    #[Test]
    public function it_handles_null_response_body(): void
    {
        $webhookLog = ContentWebhookLog::factory()->create([
            'workspace_id' => $this->workspace->id,
            'endpoint_id' => $this->endpoint->id,
            'status' => 'pending',
        ]);

        $this->logger->logSuccess(
            $webhookLog,
            durationMs: 100,
            responseCode: 204, // No content
            responseBody: null
        );

        $webhookLog->refresh();

        $this->assertEquals('completed', $webhookLog->status);
        $this->assertEquals(204, $webhookLog->response_code);
        $this->assertNull($webhookLog->response_body);
    }

    #[Test]
    public function it_handles_empty_payload(): void
    {
        $request = Request::create('/api/content/webhooks/test', 'POST');
        $verificationResult = ['verified' => true, 'reason' => 'verified'];

        $log = $this->logger->createDeliveryLog(
            $request,
            $this->endpoint,
            [],
            'generic.payload',
            $verificationResult
        );

        $this->assertNull($log->wp_id);
        $this->assertNull($log->content_type);
        $this->assertEquals([], $log->payload);
    }

    #[Test]
    public function it_returns_correct_success_rate_when_no_logs_exist(): void
    {
        // Create a new workspace with no logs
        $emptyWorkspace = Workspace::factory()->create();

        $stats = $this->logger->getDeliveryStats($emptyWorkspace->id);

        $this->assertEquals(0, $stats['total']);
        $this->assertEquals(100.0, $stats['success_rate']); // 100% when no failures
        $this->assertNull($stats['avg_duration_ms']);
    }
}
