<?php

declare(strict_types=1);

namespace Core\Content\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Core\Content\Enums\ContentType;
use Core\Content\Models\ContentItem;
use Core\Content\Models\ContentMedia;
use Core\Content\Models\ContentTaxonomy;
use Core\Content\Models\ContentWebhookEndpoint;
use Core\Content\Models\ContentWebhookLog;

/**
 * Process incoming content webhooks.
 *
 * Handles webhook payloads to create/update ContentItem records
 * from external CMS systems like WordPress.
 */
class ProcessContentWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 120;

    /**
     * Calculate the number of seconds to wait before retrying.
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ContentWebhookLog $webhookLog,
    ) {
        $this->onQueue('content-webhooks');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->webhookLog->markProcessing();

        Log::info('Processing content webhook', [
            'log_id' => $this->webhookLog->id,
            'event_type' => $this->webhookLog->event_type,
            'workspace_id' => $this->webhookLog->workspace_id,
        ]);

        try {
            $result = match (true) {
                str_starts_with($this->webhookLog->event_type, 'wordpress.') => $this->processWordPress(),
                str_starts_with($this->webhookLog->event_type, 'cms.') => $this->processCms(),
                default => $this->processGeneric(),
            };

            $this->webhookLog->markCompleted();

            // Reset failure count on endpoint
            if ($endpoint = $this->getEndpoint()) {
                $endpoint->resetFailureCount();
            }

            Log::info('Content webhook processed successfully', [
                'log_id' => $this->webhookLog->id,
                'event_type' => $this->webhookLog->event_type,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            $this->handleFailure($e);
            throw $e;
        }
    }

    /**
     * Process WordPress webhook payload.
     */
    protected function processWordPress(): array
    {
        $payload = $this->webhookLog->payload;
        $eventType = $this->webhookLog->event_type;

        return match ($eventType) {
            'wordpress.post_created', 'wordpress.post_updated', 'wordpress.post_published' => $this->upsertWordPressPost($payload),
            'wordpress.post_deleted', 'wordpress.post_trashed' => $this->deleteWordPressPost($payload),
            'wordpress.media_uploaded' => $this->processWordPressMedia($payload),
            default => ['action' => 'skipped', 'reason' => 'Unhandled WordPress event type'],
        };
    }

    /**
     * Create or update a ContentItem from WordPress data.
     */
    protected function upsertWordPressPost(array $payload): array
    {
        // Extract post data from various payload formats
        $postData = $payload['data'] ?? $payload['post'] ?? $payload;

        $wpId = $postData['ID'] ?? $postData['post_id'] ?? $postData['id'] ?? null;

        if (! $wpId) {
            return ['action' => 'skipped', 'reason' => 'No post ID found in payload'];
        }

        $workspaceId = $this->webhookLog->workspace_id;

        // Find existing or create new
        $contentItem = ContentItem::where('workspace_id', $workspaceId)
            ->where('wp_id', $wpId)
            ->first();

        $isNew = ! $contentItem;

        if ($isNew) {
            $contentItem = new ContentItem;
            $contentItem->workspace_id = $workspaceId;
            $contentItem->wp_id = $wpId;
            $contentItem->content_type = ContentType::WORDPRESS;
        }

        // Update fields from payload
        $contentItem->fill([
            'title' => $postData['post_title'] ?? $postData['title'] ?? $contentItem->title ?? 'Untitled',
            'slug' => $postData['post_name'] ?? $postData['slug'] ?? $contentItem->slug ?? 'untitled-'.$wpId,
            'type' => $this->mapWordPressPostType($postData['post_type'] ?? 'post'),
            'status' => $this->mapWordPressStatus($postData['post_status'] ?? 'draft'),
            'excerpt' => $postData['post_excerpt'] ?? $postData['excerpt'] ?? $contentItem->excerpt,
            'content_html_original' => $postData['post_content'] ?? $postData['content'] ?? $contentItem->content_html_original,
            'wp_guid' => $postData['guid'] ?? $contentItem->wp_guid,
            'wp_created_at' => isset($postData['post_date']) ? $this->parseDate($postData['post_date']) : $contentItem->wp_created_at,
            'wp_modified_at' => isset($postData['post_modified']) ? $this->parseDate($postData['post_modified']) : now(),
            'featured_media_id' => $postData['featured_media'] ?? $postData['_thumbnail_id'] ?? $contentItem->featured_media_id,
            'sync_status' => 'synced',
            'synced_at' => now(),
            'sync_error' => null,
        ]);

        $contentItem->save();

        // Process taxonomies if provided
        if (isset($postData['categories']) || isset($postData['tags'])) {
            $this->syncTaxonomies($contentItem, $postData);
        }

        return [
            'action' => $isNew ? 'created' : 'updated',
            'content_item_id' => $contentItem->id,
            'wp_id' => $wpId,
        ];
    }

    /**
     * Delete/trash a WordPress post.
     */
    protected function deleteWordPressPost(array $payload): array
    {
        $postData = $payload['data'] ?? $payload['post'] ?? $payload;
        $wpId = $postData['ID'] ?? $postData['post_id'] ?? $postData['id'] ?? null;

        if (! $wpId) {
            return ['action' => 'skipped', 'reason' => 'No post ID found in payload'];
        }

        $contentItem = ContentItem::where('workspace_id', $this->webhookLog->workspace_id)
            ->where('wp_id', $wpId)
            ->first();

        if (! $contentItem) {
            return ['action' => 'skipped', 'reason' => 'Content item not found'];
        }

        // Soft delete for trashed, hard delete for deleted
        if ($this->webhookLog->event_type === 'wordpress.post_trashed') {
            $contentItem->update(['status' => 'trash']);

            return ['action' => 'trashed', 'content_item_id' => $contentItem->id];
        }

        $contentItem->delete();

        return ['action' => 'deleted', 'wp_id' => $wpId];
    }

    /**
     * Process WordPress media upload.
     */
    protected function processWordPressMedia(array $payload): array
    {
        $mediaData = $payload['data'] ?? $payload['attachment'] ?? $payload;
        $wpId = $mediaData['ID'] ?? $mediaData['attachment_id'] ?? $mediaData['id'] ?? null;

        if (! $wpId) {
            return ['action' => 'skipped', 'reason' => 'No media ID found in payload'];
        }

        $workspaceId = $this->webhookLog->workspace_id;

        // Upsert media record
        $media = ContentMedia::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'wp_id' => $wpId,
            ],
            [
                'title' => $mediaData['title'] ?? $mediaData['post_title'] ?? null,
                'filename' => basename($mediaData['url'] ?? $mediaData['guid'] ?? 'unknown'),
                'mime_type' => $mediaData['mime_type'] ?? $mediaData['post_mime_type'] ?? 'application/octet-stream',
                'file_size' => $mediaData['filesize'] ?? 0,
                'source_url' => $mediaData['url'] ?? $mediaData['guid'] ?? $mediaData['source_url'] ?? null,
                'width' => $mediaData['width'] ?? null,
                'height' => $mediaData['height'] ?? null,
                'alt_text' => $mediaData['alt'] ?? $mediaData['alt_text'] ?? null,
                'caption' => $mediaData['caption'] ?? null,
                'sizes' => $mediaData['sizes'] ?? null,
            ]
        );

        return [
            'action' => $media->wasRecentlyCreated ? 'created' : 'updated',
            'media_id' => $media->id,
            'wp_id' => $wpId,
        ];
    }

    /**
     * Process generic CMS webhook.
     */
    protected function processCms(): array
    {
        $payload = $this->webhookLog->payload;
        $eventType = $this->webhookLog->event_type;

        // CMS events follow similar pattern to WordPress
        return match ($eventType) {
            'cms.content_created', 'cms.content_updated', 'cms.content_published' => $this->upsertCmsContent($payload),
            'cms.content_deleted' => $this->deleteCmsContent($payload),
            default => ['action' => 'skipped', 'reason' => 'Unhandled CMS event type'],
        };
    }

    /**
     * Upsert content from generic CMS payload.
     */
    protected function upsertCmsContent(array $payload): array
    {
        $contentData = $payload['content'] ?? $payload['data'] ?? $payload;

        // Require an external ID for deduplication
        $externalId = $contentData['id'] ?? $contentData['external_id'] ?? $contentData['content_id'] ?? null;

        if (! $externalId) {
            return ['action' => 'skipped', 'reason' => 'No content ID found in payload'];
        }

        $workspaceId = $this->webhookLog->workspace_id;

        // Find existing by wp_id (used for all external IDs) or create new
        $contentItem = ContentItem::where('workspace_id', $workspaceId)
            ->where('wp_id', $externalId)
            ->first();

        $isNew = ! $contentItem;

        if ($isNew) {
            $contentItem = new ContentItem;
            $contentItem->workspace_id = $workspaceId;
            $contentItem->wp_id = $externalId;
            $contentItem->content_type = ContentType::NATIVE;
        }

        $contentItem->fill([
            'title' => $contentData['title'] ?? $contentItem->title ?? 'Untitled',
            'slug' => $contentData['slug'] ?? $contentItem->slug ?? 'content-'.$externalId,
            'type' => $contentData['type'] ?? 'post',
            'status' => $contentData['status'] ?? 'draft',
            'excerpt' => $contentData['excerpt'] ?? $contentData['summary'] ?? $contentItem->excerpt,
            'content_html' => $contentData['content'] ?? $contentData['body'] ?? $contentData['html'] ?? $contentItem->content_html,
            'content_markdown' => $contentData['markdown'] ?? $contentItem->content_markdown,
            'sync_status' => 'synced',
            'synced_at' => now(),
        ]);

        $contentItem->save();

        return [
            'action' => $isNew ? 'created' : 'updated',
            'content_item_id' => $contentItem->id,
            'external_id' => $externalId,
        ];
    }

    /**
     * Delete content from generic CMS.
     */
    protected function deleteCmsContent(array $payload): array
    {
        $contentData = $payload['content'] ?? $payload['data'] ?? $payload;
        $externalId = $contentData['id'] ?? $contentData['external_id'] ?? $contentData['content_id'] ?? null;

        if (! $externalId) {
            return ['action' => 'skipped', 'reason' => 'No content ID found in payload'];
        }

        $contentItem = ContentItem::where('workspace_id', $this->webhookLog->workspace_id)
            ->where('wp_id', $externalId)
            ->first();

        if (! $contentItem) {
            return ['action' => 'skipped', 'reason' => 'Content item not found'];
        }

        $contentItem->delete();

        return ['action' => 'deleted', 'external_id' => $externalId];
    }

    /**
     * Process generic webhook payload.
     */
    protected function processGeneric(): array
    {
        $payload = $this->webhookLog->payload;

        // Generic payloads are logged but require custom handling
        // Check if there's enough data to create/update content
        if (isset($payload['title']) || isset($payload['content'])) {
            return $this->upsertCmsContent($payload);
        }

        return [
            'action' => 'logged',
            'reason' => 'Generic payload stored for manual processing',
            'payload_keys' => array_keys($payload),
        ];
    }

    // -------------------------------------------------------------------------
    // Helper Methods
    // -------------------------------------------------------------------------

    /**
     * Get the webhook endpoint if linked.
     */
    protected function getEndpoint(): ?ContentWebhookEndpoint
    {
        if ($this->webhookLog->endpoint_id) {
            return ContentWebhookEndpoint::find($this->webhookLog->endpoint_id);
        }

        return null;
    }

    /**
     * Map WordPress post type to ContentItem type.
     */
    protected function mapWordPressPostType(string $wpType): string
    {
        return match ($wpType) {
            'post' => 'post',
            'page' => 'page',
            'attachment' => 'attachment',
            default => 'post',
        };
    }

    /**
     * Map WordPress status to ContentItem status.
     */
    protected function mapWordPressStatus(string $wpStatus): string
    {
        return match ($wpStatus) {
            'publish' => 'publish',
            'draft' => 'draft',
            'pending' => 'pending',
            'private' => 'private',
            'future' => 'future',
            'trash' => 'trash',
            default => 'draft',
        };
    }

    /**
     * Parse date string to Carbon instance.
     */
    protected function parseDate(string $date): ?\Carbon\Carbon
    {
        try {
            return \Carbon\Carbon::parse($date);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Sync taxonomies from payload.
     */
    protected function syncTaxonomies(ContentItem $contentItem, array $payload): void
    {
        $taxonomyIds = [];

        // Process categories
        if (isset($payload['categories'])) {
            foreach ((array) $payload['categories'] as $category) {
                $taxonomy = $this->findOrCreateTaxonomy($contentItem->workspace_id, $category, 'category');
                if ($taxonomy) {
                    $taxonomyIds[] = $taxonomy->id;
                }
            }
        }

        // Process tags
        if (isset($payload['tags'])) {
            foreach ((array) $payload['tags'] as $tag) {
                $taxonomy = $this->findOrCreateTaxonomy($contentItem->workspace_id, $tag, 'tag');
                if ($taxonomy) {
                    $taxonomyIds[] = $taxonomy->id;
                }
            }
        }

        if (! empty($taxonomyIds)) {
            $contentItem->taxonomies()->sync($taxonomyIds);
        }
    }

    /**
     * Find or create a taxonomy record.
     */
    protected function findOrCreateTaxonomy(int $workspaceId, array|int|string $data, string $type): ?ContentTaxonomy
    {
        // Handle array with ID/name
        if (is_array($data)) {
            $wpId = $data['term_id'] ?? $data['id'] ?? null;
            $name = $data['name'] ?? null;
            $slug = $data['slug'] ?? null;
        } elseif (is_numeric($data)) {
            // Just an ID
            $wpId = (int) $data;
            $name = null;
            $slug = null;
        } else {
            // Just a name/slug
            $wpId = null;
            $name = $data;
            $slug = \Illuminate\Support\Str::slug($data);
        }

        if (! $wpId && ! $name) {
            return null;
        }

        // Try to find by wp_id first
        if ($wpId) {
            $taxonomy = ContentTaxonomy::where('workspace_id', $workspaceId)
                ->where('wp_id', $wpId)
                ->where('type', $type)
                ->first();

            if ($taxonomy) {
                return $taxonomy;
            }
        }

        // Try to find by slug
        if ($slug) {
            $taxonomy = ContentTaxonomy::where('workspace_id', $workspaceId)
                ->where('slug', $slug)
                ->where('type', $type)
                ->first();

            if ($taxonomy) {
                return $taxonomy;
            }
        }

        // Create new taxonomy if we have enough info
        if ($name || $slug) {
            return ContentTaxonomy::create([
                'workspace_id' => $workspaceId,
                'wp_id' => $wpId,
                'type' => $type,
                'name' => $name ?? $slug,
                'slug' => $slug ?? \Illuminate\Support\Str::slug($name),
            ]);
        }

        return null;
    }

    /**
     * Handle job failure.
     */
    protected function handleFailure(\Exception $e): void
    {
        $this->webhookLog->markFailed($e->getMessage());

        // Increment failure count on endpoint
        if ($endpoint = $this->getEndpoint()) {
            $endpoint->incrementFailureCount();
        }

        Log::error('Content webhook processing failed', [
            'log_id' => $this->webhookLog->id,
            'event_type' => $this->webhookLog->event_type,
            'error' => $e->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }

    /**
     * Handle a job failure (called by Laravel).
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Content webhook job failed permanently', [
            'log_id' => $this->webhookLog->id,
            'event_type' => $this->webhookLog->event_type,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $this->webhookLog->markFailed(
            "Processing failed after {$this->attempts()} attempts: {$exception->getMessage()}"
        );
    }
}
