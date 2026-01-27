<?php

declare(strict_types=1);

namespace Core\Mod\Content\Services;

use Illuminate\Support\Facades\Log;
use Core\Mod\Content\Models\ContentItem;
use Plug\Cdn\CdnManager;
use Plug\Response;

/**
 * CDN cache purge service for content.
 *
 * Integrates with the Plug\Cdn infrastructure to purge Bunny CDN
 * cache when content is published or updated.
 */
class CdnPurgeService
{
    public function __construct(
        protected CdnManager $cdn
    ) {}

    /**
     * Check if CDN purging is enabled.
     */
    public function isEnabled(): bool
    {
        return config('cdn.pipeline.auto_purge', false)
            && config('cdn.bunny.api_key')
            && config('cdn.bunny.pull_zone_id');
    }

    /**
     * Purge CDN cache for a content item.
     *
     * Uses the content item's getCdnUrlsForPurge attribute to determine
     * which URLs need purging.
     */
    public function purgeContent(ContentItem $content): Response
    {
        if (! $this->isEnabled()) {
            Log::debug('CdnPurgeService: Skipping purge - not enabled or not configured');

            return new Response(
                \Plug\Enum\Status::OK,
                ['skipped' => true, 'reason' => 'CDN purge not enabled']
            );
        }

        $urls = $content->cdn_urls_for_purge;

        if (empty($urls)) {
            Log::debug('CdnPurgeService: No URLs to purge for content', [
                'content_id' => $content->id,
            ]);

            return new Response(
                \Plug\Enum\Status::OK,
                ['skipped' => true, 'reason' => 'No URLs to purge']
            );
        }

        Log::info('CdnPurgeService: Purging CDN cache for content', [
            'content_id' => $content->id,
            'content_slug' => $content->slug,
            'url_count' => count($urls),
            'urls' => $urls,
        ]);

        $response = $this->cdn->purge()->urls($urls);

        if ($response->isOk()) {
            // Update the content item to record the purge
            $content->updateQuietly([
                'cdn_purged_at' => now(),
            ]);

            Log::info('CdnPurgeService: Successfully purged CDN cache', [
                'content_id' => $content->id,
                'purged_count' => $response->get('purged', count($urls)),
            ]);
        } else {
            Log::error('CdnPurgeService: Failed to purge CDN cache', [
                'content_id' => $content->id,
                'error' => $response->getMessage(),
                'context' => $response->context(),
            ]);
        }

        return $response;
    }

    /**
     * Purge specific URLs from CDN cache.
     *
     * @param  array<string>  $urls
     */
    public function purgeUrls(array $urls): Response
    {
        if (! $this->isEnabled()) {
            return new Response(
                \Plug\Enum\Status::OK,
                ['skipped' => true, 'reason' => 'CDN purge not enabled']
            );
        }

        if (empty($urls)) {
            return new Response(
                \Plug\Enum\Status::OK,
                ['skipped' => true, 'reason' => 'No URLs provided']
            );
        }

        return $this->cdn->purge()->urls($urls);
    }

    /**
     * Purge all CDN cache for a workspace.
     *
     * Uses tag-based purging for workspace isolation.
     */
    public function purgeWorkspace(string $workspaceUuid): Response
    {
        if (! $this->isEnabled()) {
            return new Response(
                \Plug\Enum\Status::OK,
                ['skipped' => true, 'reason' => 'CDN purge not enabled']
            );
        }

        Log::info('CdnPurgeService: Purging CDN cache for workspace', [
            'workspace_uuid' => $workspaceUuid,
        ]);

        return $this->cdn->purge()->tag("workspace-{$workspaceUuid}");
    }
}
