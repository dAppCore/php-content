<?php

declare(strict_types=1);

namespace Core\Mod\Content\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Core\Mod\Content\Jobs\ProcessContentWebhook;
use Core\Mod\Content\Models\ContentWebhookEndpoint;
use Core\Mod\Content\Models\ContentWebhookLog;

/**
 * Controller for receiving external content webhooks.
 *
 * Handles incoming webhooks from WordPress, CMS systems, and custom integrations.
 * Webhooks are logged and dispatched to a job for async processing.
 */
class ContentWebhookController extends Controller
{
    /**
     * Receive a webhook from an external source.
     *
     * POST /api/content/webhooks/{endpoint}
     */
    public function receive(Request $request, ContentWebhookEndpoint $endpoint): Response
    {
        // Check if endpoint is enabled
        if (! $endpoint->isEnabled()) {
            Log::warning('Content webhook received for disabled endpoint', [
                'endpoint_id' => $endpoint->id,
                'endpoint_uuid' => $endpoint->uuid,
            ]);

            return response('Endpoint disabled', 403);
        }

        // Check circuit breaker
        if ($endpoint->isCircuitBroken()) {
            Log::warning('Content webhook endpoint circuit breaker open', [
                'endpoint_id' => $endpoint->id,
                'failure_count' => $endpoint->failure_count,
            ]);

            return response('Service unavailable', 503);
        }

        // Get raw payload
        $payload = $request->getContent();

        // Verify signature if secret is configured
        $signature = $this->extractSignature($request);
        if (! $endpoint->verifySignature($payload, $signature)) {
            Log::warning('Content webhook signature verification failed', [
                'endpoint_id' => $endpoint->id,
                'source_ip' => $request->ip(),
            ]);

            return response('Invalid signature', 401);
        }

        // Parse payload
        $data = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Content webhook invalid JSON payload', [
                'endpoint_id' => $endpoint->id,
                'error' => json_last_error_msg(),
            ]);

            return response('Invalid JSON payload', 400);
        }

        // Determine event type
        $eventType = $this->determineEventType($request, $data);

        // Check if event type is allowed
        if (! $endpoint->isTypeAllowed($eventType)) {
            Log::info('Content webhook event type not allowed', [
                'endpoint_id' => $endpoint->id,
                'event_type' => $eventType,
                'allowed_types' => $endpoint->allowed_types,
            ]);

            return response('Event type not allowed', 403);
        }

        // Create webhook log entry
        $log = ContentWebhookLog::create([
            'workspace_id' => $endpoint->workspace_id,
            'endpoint_id' => $endpoint->id,
            'event_type' => $eventType,
            'wp_id' => $this->extractContentId($data),
            'content_type' => $this->extractContentType($data),
            'payload' => $data,
            'status' => 'pending',
            'source_ip' => $request->ip(),
        ]);

        Log::info('Content webhook received', [
            'log_id' => $log->id,
            'endpoint_id' => $endpoint->id,
            'event_type' => $eventType,
            'workspace_id' => $endpoint->workspace_id,
        ]);

        // Update endpoint last received timestamp
        $endpoint->markReceived();

        // Dispatch job for async processing
        ProcessContentWebhook::dispatch($log);

        return response('Accepted', 202);
    }

    /**
     * Extract signature from request headers.
     *
     * Supports multiple header formats.
     */
    protected function extractSignature(Request $request): ?string
    {
        // Try various signature header formats
        $signatureHeaders = [
            'X-Signature',
            'X-Hub-Signature-256',
            'X-WP-Webhook-Signature',
            'X-Content-Signature',
            'Signature',
        ];

        foreach ($signatureHeaders as $header) {
            $value = $request->header($header);
            if ($value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Determine the event type from the request and payload.
     */
    protected function determineEventType(Request $request, array $data): string
    {
        // Check explicit event type in headers
        $headerEventType = $request->header('X-Event-Type')
            ?? $request->header('X-WP-Webhook-Event')
            ?? $request->header('X-Content-Event');

        if ($headerEventType) {
            return $this->normaliseEventType($headerEventType);
        }

        // Check event type in payload
        if (isset($data['event'])) {
            return $this->normaliseEventType($data['event']);
        }

        if (isset($data['event_type'])) {
            return $this->normaliseEventType($data['event_type']);
        }

        if (isset($data['action'])) {
            return $this->normaliseEventType($data['action']);
        }

        // WordPress-style hook name
        if (isset($data['hook'])) {
            return $this->mapWordPressHook($data['hook']);
        }

        // Detect WordPress payload structure
        if ($this->isWordPressPayload($data)) {
            return $this->inferWordPressEventType($data);
        }

        // Fallback to generic payload
        return 'generic.payload';
    }

    /**
     * Normalise event type to standard format.
     */
    protected function normaliseEventType(string $eventType): string
    {
        // Convert underscores to dots for consistency
        $normalised = str_replace('_', '.', strtolower($eventType));

        // Map common variations
        $mappings = [
            'post.created' => 'wordpress.post_created',
            'post.updated' => 'wordpress.post_updated',
            'post.deleted' => 'wordpress.post_deleted',
            'post.published' => 'wordpress.post_published',
            'post.trashed' => 'wordpress.post_trashed',
            'content.created' => 'cms.content_created',
            'content.updated' => 'cms.content_updated',
            'content.deleted' => 'cms.content_deleted',
            'content.published' => 'cms.content_published',
        ];

        // Check if already has a namespace prefix
        if (str_contains($normalised, '.')) {
            $parts = explode('.', $normalised);
            if (in_array($parts[0], ['wordpress', 'cms', 'generic'])) {
                // Convert dots back to underscores for action part
                $namespace = $parts[0];
                $action = implode('_', array_slice($parts, 1));

                return $namespace.'.'.$action;
            }
        }

        return $mappings[$normalised] ?? 'generic.payload';
    }

    /**
     * Map WordPress hook names to event types.
     */
    protected function mapWordPressHook(string $hook): string
    {
        $hookMappings = [
            'save_post' => 'wordpress.post_updated',
            'publish_post' => 'wordpress.post_published',
            'wp_insert_post' => 'wordpress.post_created',
            'before_delete_post' => 'wordpress.post_deleted',
            'wp_trash_post' => 'wordpress.post_trashed',
            'add_attachment' => 'wordpress.media_uploaded',
            'edit_attachment' => 'wordpress.media_uploaded',
        ];

        return $hookMappings[$hook] ?? 'wordpress.post_updated';
    }

    /**
     * Check if payload appears to be from WordPress.
     */
    protected function isWordPressPayload(array $data): bool
    {
        // Check for WordPress-specific fields
        return isset($data['post_id'])
            || isset($data['ID'])
            || isset($data['post_type'])
            || isset($data['post_status'])
            || isset($data['guid'])
            || (isset($data['data']) && isset($data['data']['post_id']));
    }

    /**
     * Infer WordPress event type from payload content.
     */
    protected function inferWordPressEventType(array $data): string
    {
        $status = $data['post_status']
            ?? $data['data']['post_status']
            ?? null;

        if ($status === 'publish') {
            return 'wordpress.post_published';
        }

        if ($status === 'trash') {
            return 'wordpress.post_trashed';
        }

        // Check if this looks like a new post (no modified date or same as created)
        $created = $data['post_date'] ?? $data['data']['post_date'] ?? null;
        $modified = $data['post_modified'] ?? $data['data']['post_modified'] ?? null;

        if ($created && $modified && $created === $modified) {
            return 'wordpress.post_created';
        }

        return 'wordpress.post_updated';
    }

    /**
     * Extract content ID from payload.
     */
    protected function extractContentId(array $data): ?int
    {
        // Try various ID field names
        $idFields = ['post_id', 'ID', 'id', 'content_id', 'item_id'];

        foreach ($idFields as $field) {
            if (isset($data[$field])) {
                return (int) $data[$field];
            }

            // Check nested data
            if (isset($data['data'][$field])) {
                return (int) $data['data'][$field];
            }
        }

        return null;
    }

    /**
     * Extract content type from payload.
     */
    protected function extractContentType(array $data): ?string
    {
        // Try various type field names
        $typeFields = ['post_type', 'content_type', 'type'];

        foreach ($typeFields as $field) {
            if (isset($data[$field])) {
                return (string) $data[$field];
            }

            // Check nested data
            if (isset($data['data'][$field])) {
                return (string) $data['data'][$field];
            }
        }

        return null;
    }
}
