<?php

declare(strict_types=1);

namespace Core\Content\Observers;

use Illuminate\Support\Facades\Log;
use Core\Content\Models\ContentItem;
use Core\Content\Services\CdnPurgeService;

/**
 * Content Item Observer - handles CDN cache purging on content changes.
 *
 * Automatically purges CDN cache when published content is updated,
 * or when content status changes to/from published.
 */
class ContentItemObserver
{
    public function __construct(
        protected CdnPurgeService $cdnPurge
    ) {}

    /**
     * Handle the ContentItem "updated" event.
     *
     * Purges CDN cache when:
     * - Published content is modified
     * - Content status changes to "publish"
     */
    public function updated(ContentItem $content): void
    {
        // Check if status changed to published
        $wasPublished = $content->getOriginal('status') === 'publish';
        $isPublished = $content->status === 'publish';

        // Purge if: newly published OR was already published and content changed
        if ($isPublished && (! $wasPublished || $this->hasContentChanged($content))) {
            $this->queuePurge($content);
        }
    }

    /**
     * Handle the ContentItem "created" event.
     *
     * Purges CDN cache if content is created in published state.
     */
    public function created(ContentItem $content): void
    {
        if ($content->status === 'publish') {
            $this->queuePurge($content);
        }
    }

    /**
     * Handle the ContentItem "deleted" event.
     *
     * Purges CDN cache when published content is deleted.
     */
    public function deleted(ContentItem $content): void
    {
        if ($content->status === 'publish') {
            $this->queuePurge($content);
        }
    }

    /**
     * Check if content fields that affect the public page have changed.
     */
    protected function hasContentChanged(ContentItem $content): bool
    {
        $watchedFields = [
            'title',
            'slug',
            'content_html',
            'content_html_clean',
            'content_markdown',
            'excerpt',
            'featured_media_id',
            'seo_meta',
        ];

        foreach ($watchedFields as $field) {
            if ($content->isDirty($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Queue the CDN purge operation.
     *
     * Currently runs synchronously, but could be dispatched to queue
     * for better performance if needed.
     */
    protected function queuePurge(ContentItem $content): void
    {
        try {
            $this->cdnPurge->purgeContent($content);
        } catch (\Exception $e) {
            // Log but don't fail the save operation
            Log::error('ContentItemObserver: CDN purge failed', [
                'content_id' => $content->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
