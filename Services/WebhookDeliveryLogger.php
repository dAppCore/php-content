<?php

declare(strict_types=1);

namespace Core\Mod\Content\Services;

use Core\Mod\Content\Models\ContentWebhookEndpoint;
use Core\Mod\Content\Models\ContentWebhookLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WebhookDeliveryLogger
 *
 * Centralised service for logging webhook deliveries, signature verification
 * results, and processing metrics. Provides comprehensive audit trails for
 * security and debugging purposes.
 *
 * P2-082: Implements signature verification audit logging
 * P2-083: Implements comprehensive delivery logging
 */
class WebhookDeliveryLogger
{
    /**
     * Headers that are safe to log (excluding sensitive data like signatures).
     */
    protected const SAFE_HEADERS = [
        'Content-Type',
        'Content-Length',
        'User-Agent',
        'X-Event-Type',
        'X-WP-Webhook-Event',
        'X-Content-Event',
        'X-Request-Id',
        'X-Correlation-Id',
        'Accept',
        'Accept-Encoding',
    ];

    /**
     * Log a successful webhook delivery with full details.
     *
     * @param  int  $durationMs  Processing duration in milliseconds
     * @param  int|null  $responseCode  HTTP response code if applicable
     * @param  string|null  $responseBody  Response body if applicable
     */
    public function logSuccess(
        ContentWebhookLog $webhookLog,
        int $durationMs,
        ?int $responseCode = null,
        ?string $responseBody = null
    ): void {
        $webhookLog->markCompletedWithDetails(
            $durationMs,
            $responseCode,
            $responseBody
        );

        Log::info('Webhook delivery successful', [
            'log_id' => $webhookLog->id,
            'endpoint_id' => $webhookLog->endpoint_id,
            'event_type' => $webhookLog->event_type,
            'workspace_id' => $webhookLog->workspace_id,
            'duration_ms' => $durationMs,
            'response_code' => $responseCode,
        ]);
    }

    /**
     * Log a failed webhook delivery with full details.
     *
     * @param  string  $error  Error message
     * @param  int  $durationMs  Processing duration in milliseconds
     * @param  int|null  $responseCode  HTTP response code if applicable
     * @param  string|null  $responseBody  Response body if applicable
     */
    public function logFailure(
        ContentWebhookLog $webhookLog,
        string $error,
        int $durationMs,
        ?int $responseCode = null,
        ?string $responseBody = null
    ): void {
        $webhookLog->markFailedWithDetails(
            $error,
            $durationMs,
            $responseCode,
            $responseBody
        );

        Log::warning('Webhook delivery failed', [
            'log_id' => $webhookLog->id,
            'endpoint_id' => $webhookLog->endpoint_id,
            'event_type' => $webhookLog->event_type,
            'workspace_id' => $webhookLog->workspace_id,
            'error' => $error,
            'duration_ms' => $durationMs,
            'response_code' => $responseCode,
        ]);
    }

    /**
     * Log a signature verification failure for security auditing.
     *
     * Creates a log entry specifically for tracking failed signature
     * verification attempts, which is critical for detecting potential
     * attacks or misconfigured integrations.
     *
     * @param  Request  $request  The incoming request
     * @param  ContentWebhookEndpoint  $endpoint  The webhook endpoint
     * @param  string  $failureReason  Reason for signature failure
     * @return ContentWebhookLog The created log entry
     */
    public function logSignatureFailure(
        Request $request,
        ContentWebhookEndpoint $endpoint,
        string $failureReason
    ): ContentWebhookLog {
        $log = ContentWebhookLog::create([
            'workspace_id' => $endpoint->workspace_id,
            'endpoint_id' => $endpoint->id,
            'event_type' => 'signature_verification_failed',
            'payload' => null, // Don't store potentially malicious payload
            'request_headers' => $this->extractSafeHeaders($request),
            'status' => 'failed',
            'source_ip' => $request->ip(),
            'signature_verified' => false,
            'signature_failure_reason' => $failureReason,
            'error_message' => 'Signature verification failed: '.$failureReason,
            'processed_at' => now(),
        ]);

        Log::warning('Webhook signature verification failed', [
            'log_id' => $log->id,
            'endpoint_id' => $endpoint->id,
            'endpoint_uuid' => $endpoint->uuid,
            'source_ip' => $request->ip(),
            'failure_reason' => $failureReason,
            'require_signature' => $endpoint->requiresSignature(),
        ]);

        return $log;
    }

    /**
     * Log a successful signature verification.
     *
     * @param  ContentWebhookLog  $webhookLog  The webhook log entry
     * @param  string  $verificationMethod  How the signature was verified (e.g., 'current_secret', 'grace_period')
     */
    public function logSignatureSuccess(
        ContentWebhookLog $webhookLog,
        string $verificationMethod
    ): void {
        $webhookLog->recordSignatureVerification(true, $verificationMethod);

        Log::debug('Webhook signature verified', [
            'log_id' => $webhookLog->id,
            'endpoint_id' => $webhookLog->endpoint_id,
            'method' => $verificationMethod,
        ]);
    }

    /**
     * Log when signature verification is bypassed (not required).
     *
     * This is an important security audit event - we track when webhooks
     * are accepted without signature verification.
     *
     * @param  Request  $request  The incoming request
     * @param  ContentWebhookEndpoint  $endpoint  The webhook endpoint
     */
    public function logSignatureNotRequired(
        Request $request,
        ContentWebhookEndpoint $endpoint
    ): void {
        Log::warning('Webhook accepted without signature verification (explicitly disabled)', [
            'endpoint_id' => $endpoint->id,
            'endpoint_uuid' => $endpoint->uuid,
            'endpoint_name' => $endpoint->name,
            'source_ip' => $request->ip(),
            'workspace_id' => $endpoint->workspace_id,
        ]);
    }

    /**
     * Create a new webhook log entry for an incoming request.
     *
     * @param  Request  $request  The incoming request
     * @param  ContentWebhookEndpoint  $endpoint  The webhook endpoint
     * @param  array  $payload  The parsed payload
     * @param  string  $eventType  The determined event type
     * @param  array  $verificationResult  The signature verification result
     */
    public function createDeliveryLog(
        Request $request,
        ContentWebhookEndpoint $endpoint,
        array $payload,
        string $eventType,
        array $verificationResult
    ): ContentWebhookLog {
        return ContentWebhookLog::create([
            'workspace_id' => $endpoint->workspace_id,
            'endpoint_id' => $endpoint->id,
            'event_type' => $eventType,
            'wp_id' => $this->extractContentId($payload),
            'content_type' => $this->extractContentType($payload),
            'payload' => $payload,
            'request_headers' => $this->extractSafeHeaders($request),
            'status' => 'pending',
            'source_ip' => $request->ip(),
            'signature_verified' => $verificationResult['verified'],
            'signature_failure_reason' => $verificationResult['verified'] ? null : $verificationResult['reason'],
        ]);
    }

    /**
     * Record processing metrics for a webhook.
     *
     * @param  ContentWebhookLog  $webhookLog  The webhook log entry
     * @param  int  $durationMs  Processing duration in milliseconds
     * @param  array|null  $result  Processing result details
     */
    public function recordProcessingMetrics(
        ContentWebhookLog $webhookLog,
        int $durationMs,
        ?array $result = null
    ): void {
        $webhookLog->recordProcessingComplete($durationMs);

        Log::info('Webhook processing metrics recorded', [
            'log_id' => $webhookLog->id,
            'duration_ms' => $durationMs,
            'result_action' => $result['action'] ?? 'unknown',
        ]);
    }

    /**
     * Get delivery statistics for a workspace or endpoint.
     *
     * @param  int|null  $workspaceId  Filter by workspace (null for all)
     * @param  int|null  $endpointId  Filter by endpoint (null for all)
     * @param  int  $days  Number of days to look back
     * @return array{
     *     total: int,
     *     successful: int,
     *     failed: int,
     *     pending: int,
     *     signature_failures: int,
     *     avg_duration_ms: float|null,
     *     success_rate: float
     * }
     */
    public function getDeliveryStats(
        ?int $workspaceId = null,
        ?int $endpointId = null,
        int $days = 30
    ): array {
        $query = ContentWebhookLog::query()
            ->where('created_at', '>=', now()->subDays($days));

        if ($workspaceId !== null) {
            $query->where('workspace_id', $workspaceId);
        }

        if ($endpointId !== null) {
            $query->where('endpoint_id', $endpointId);
        }

        $total = (clone $query)->count();
        $successful = (clone $query)->where('status', 'completed')->count();
        $failed = (clone $query)->where('status', 'failed')->count();
        $pending = (clone $query)->where('status', 'pending')->count();
        $signatureFailures = (clone $query)->where('signature_verified', false)->count();

        $avgDuration = (clone $query)
            ->whereNotNull('processing_duration_ms')
            ->avg('processing_duration_ms');

        return [
            'total' => $total,
            'successful' => $successful,
            'failed' => $failed,
            'pending' => $pending,
            'signature_failures' => $signatureFailures,
            'avg_duration_ms' => $avgDuration !== null ? round((float) $avgDuration, 2) : null,
            'success_rate' => $total > 0 ? round(($successful / $total) * 100, 2) : 100.0,
        ];
    }

    /**
     * Get recent signature verification failures for security monitoring.
     *
     * @param  int|null  $workspaceId  Filter by workspace
     * @param  int  $limit  Maximum number of failures to return
     * @return \Illuminate\Database\Eloquent\Collection<ContentWebhookLog>
     */
    public function getRecentSignatureFailures(
        ?int $workspaceId = null,
        int $limit = 50
    ): \Illuminate\Database\Eloquent\Collection {
        $query = ContentWebhookLog::query()
            ->where('signature_verified', false)
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($workspaceId !== null) {
            $query->where('workspace_id', $workspaceId);
        }

        return $query->get();
    }

    /**
     * Extract headers that are safe to log.
     *
     * @param  Request  $request  The incoming request
     * @return array<string, string>
     */
    public function extractSafeHeaders(Request $request): array
    {
        $headers = [];

        foreach (self::SAFE_HEADERS as $header) {
            $value = $request->header($header);
            if ($value !== null) {
                $headers[$header] = $value;
            }
        }

        return $headers;
    }

    /**
     * Extract content ID from payload.
     *
     * @param  array  $data  The webhook payload
     */
    protected function extractContentId(array $data): ?int
    {
        $idFields = ['post_id', 'ID', 'id', 'content_id', 'item_id'];

        foreach ($idFields as $field) {
            if (isset($data[$field])) {
                return (int) $data[$field];
            }

            if (isset($data['data'][$field])) {
                return (int) $data['data'][$field];
            }
        }

        return null;
    }

    /**
     * Extract content type from payload.
     *
     * @param  array  $data  The webhook payload
     */
    protected function extractContentType(array $data): ?string
    {
        $typeFields = ['post_type', 'content_type', 'type'];

        foreach ($typeFields as $field) {
            if (isset($data[$field])) {
                return (string) $data[$field];
            }

            if (isset($data['data'][$field])) {
                return (string) $data['data'][$field];
            }
        }

        return null;
    }
}
