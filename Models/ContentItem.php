<?php

declare(strict_types=1);

namespace Core\Content\Models;

use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;
use Core\Seo\HasSeoMetadata;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Core\Content\Enums\ContentType;
use Core\Content\Observers\ContentItemObserver;

#[ObservedBy([ContentItemObserver::class])]
class ContentItem extends Model
{
    use HasFactory, HasSeoMetadata, SoftDeletes;

    protected static function newFactory(): \Core\Content\Database\Factories\ContentItemFactory
    {
        return \Core\Content\Database\Factories\ContentItemFactory::new();
    }

    protected $fillable = [
        'workspace_id',
        'content_type',
        'author_id',
        'last_edited_by',
        'wp_id',
        'wp_guid',
        'type',
        'status',
        'publish_at',
        'slug',
        'title',
        'excerpt',
        'content_html_original',
        'content_html_clean',
        'content_html',
        'content_markdown',
        'content_json',
        'editor_state',
        'wp_created_at',
        'wp_modified_at',
        'featured_media_id',
        'seo_meta',
        'sync_status',
        'synced_at',
        'sync_error',
        'revision_count',
        'cdn_urls',
        'cdn_purged_at',
        'preview_token',
        'preview_expires_at',
    ];

    protected $casts = [
        'content_type' => ContentType::class,
        'content_json' => 'array',
        'editor_state' => 'array',
        'seo_meta' => 'array',
        'cdn_urls' => 'array',
        'wp_created_at' => 'datetime',
        'wp_modified_at' => 'datetime',
        'publish_at' => 'datetime',
        'synced_at' => 'datetime',
        'cdn_purged_at' => 'datetime',
        'preview_expires_at' => 'datetime',
        'revision_count' => 'integer',
    ];

    /**
     * Get the workspace this content belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the author of this content.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(ContentAuthor::class, 'author_id');
    }

    /**
     * Get the user who last edited this content.
     */
    public function lastEditedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    /**
     * Get the revision history for this content.
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(ContentRevision::class)->orderByDesc('revision_number');
    }

    /**
     * Get the featured media for this content.
     */
    public function featuredMedia(): BelongsTo
    {
        return $this->belongsTo(ContentMedia::class, 'featured_media_id', 'wp_id')
            ->where('workspace_id', $this->workspace_id);
    }

    /**
     * Get the taxonomies (categories and tags) for this content.
     */
    public function taxonomies(): BelongsToMany
    {
        return $this->belongsToMany(ContentTaxonomy::class, 'content_item_taxonomy')
            ->withTimestamps();
    }

    /**
     * Get only categories.
     */
    public function categories(): BelongsToMany
    {
        return $this->taxonomies()->where('type', 'category');
    }

    /**
     * Get only tags.
     */
    public function tags(): BelongsToMany
    {
        return $this->taxonomies()->where('type', 'tag');
    }

    /**
     * Scope to filter by workspace.
     */
    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    /**
     * Scope to only published content.
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'publish');
    }

    /**
     * Scope to only posts.
     */
    public function scopePosts($query)
    {
        return $query->where('type', 'post');
    }

    /**
     * Scope to only pages.
     */
    public function scopePages($query)
    {
        return $query->where('type', 'page');
    }

    /**
     * Scope to items needing sync.
     */
    public function scopeNeedsSync($query)
    {
        return $query->whereIn('sync_status', ['pending', 'failed', 'stale']);
    }

    /**
     * Scope to find by slug.
     */
    public function scopeBySlug($query, string $slug)
    {
        return $query->where('slug', $slug);
    }

    /**
     * Scope to filter by slug prefix (e.g., 'help/' for help articles).
     */
    public function scopeWithSlugPrefix($query, string $prefix)
    {
        return $query->where('slug', 'like', $prefix.'%');
    }

    /**
     * Scope to help articles (pages with 'help' category or 'help/' slug prefix).
     */
    public function scopeHelpArticles($query)
    {
        return $query->where(function ($q) {
            // Match pages with 'help/' slug prefix
            $q->where('slug', 'like', 'help/%')
                // Or pages in a 'help' category
                ->orWhereHas('categories', function ($catQuery) {
                    $catQuery->where('slug', 'help')
                        ->orWhere('slug', 'help-articles')
                        ->orWhere('name', 'like', '%help%');
                });
        });
    }

    /**
     * Scope to filter by content type.
     */
    public function scopeOfContentType($query, ContentType|string $contentType)
    {
        $value = $contentType instanceof ContentType ? $contentType->value : $contentType;

        return $query->where('content_type', $value);
    }

    /**
     * Scope to only WordPress content (legacy).
     */
    public function scopeWordpress($query)
    {
        return $query->where('content_type', ContentType::WORDPRESS->value);
    }

    /**
     * Scope to only native Host UK content.
     */
    public function scopeHostuk($query)
    {
        return $query->where('content_type', ContentType::HOSTUK->value);
    }

    /**
     * Scope to only satellite content.
     */
    public function scopeSatellite($query)
    {
        return $query->where('content_type', ContentType::SATELLITE->value);
    }

    /**
     * Scope to only native content (non-WordPress).
     * Includes: native, hostuk, satellite
     */
    public function scopeNative($query)
    {
        return $query->whereIn('content_type', ContentType::nativeTypeValues());
    }

    /**
     * Scope to only strictly native content (new default type).
     */
    public function scopeStrictlyNative($query)
    {
        return $query->where('content_type', ContentType::NATIVE->value);
    }

    /**
     * Check if this is WordPress content (legacy).
     */
    public function isWordpress(): bool
    {
        return $this->content_type === ContentType::WORDPRESS;
    }

    /**
     * Check if this is native Host UK content.
     */
    public function isHostuk(): bool
    {
        return $this->content_type === ContentType::HOSTUK;
    }

    /**
     * Check if this is satellite content.
     */
    public function isSatellite(): bool
    {
        return $this->content_type === ContentType::SATELLITE;
    }

    /**
     * Check if this is strictly native content (new default type).
     */
    public function isNative(): bool
    {
        return $this->content_type === ContentType::NATIVE;
    }

    /**
     * Check if this is any native content type (non-WordPress).
     */
    public function isAnyNative(): bool
    {
        return $this->content_type?->isNative() ?? false;
    }

    /**
     * Check if this content uses the Flux editor (non-WordPress).
     */
    public function usesFluxEditor(): bool
    {
        return $this->content_type?->usesFluxEditor() ?? false;
    }

    /**
     * Get the display content (prefers clean HTML, falls back to markdown).
     */
    public function getDisplayContentAttribute(): string
    {
        if ($this->usesFluxEditor()) {
            return $this->content_html ?? $this->content_markdown ?? '';
        }

        return $this->content_html_clean ?? $this->content_html_original ?? '';
    }

    /**
     * Get sanitised HTML content for safe rendering.
     *
     * Uses HTMLPurifier to remove XSS vectors while preserving
     * safe HTML elements like paragraphs, headings, lists, etc.
     */
    public function getSanitisedContent(): string
    {
        $content = $this->display_content;

        if (empty($content)) {
            return '';
        }

        // Use the StaticPageSanitiser if available
        if (class_exists(\Mod\Bio\Services\StaticPageSanitiser::class)) {
            return app(\Mod\Bio\Services\StaticPageSanitiser::class)->sanitiseHtml($content);
        }

        // Fallback: basic sanitisation using strip_tags with allowed tags
        $allowedTags = '<p><br><strong><b><em><i><u><h1><h2><h3><h4><h5><h6><ul><ol><li><a><blockquote><pre><code><img><table><thead><tbody><tr><th><td><div><span><hr>';

        return strip_tags($content, $allowedTags);
    }

    /**
     * Get URLs that need CDN purge when this content changes.
     */
    public function getCdnUrlsForPurgeAttribute(): array
    {
        $workspace = $this->workspace;
        if (! $workspace) {
            return [];
        }

        $domain = $workspace->domain;
        $urls = [];

        // Main content URL
        if ($this->type === 'post') {
            $urls[] = "https://{$domain}/blog/{$this->slug}";
            $urls[] = "https://{$domain}/blog"; // Blog listing
        } elseif ($this->type === 'page') {
            $urls[] = "https://{$domain}/{$this->slug}";
        }

        // Homepage always
        $urls[] = "https://{$domain}/";
        $urls[] = "https://{$domain}";

        return $urls;
    }

    /**
     * Mark as synced.
     */
    public function markSynced(): void
    {
        $this->update([
            'sync_status' => 'synced',
            'synced_at' => now(),
            'sync_error' => null,
        ]);
    }

    /**
     * Mark as failed.
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'sync_status' => 'failed',
            'sync_error' => $error,
        ]);
    }

    /**
     * Get Flux badge colour for content status.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'publish' => 'green',
            'draft' => 'yellow',
            'pending' => 'orange',
            'future' => 'blue',
            'private' => 'zinc',
            default => 'zinc',
        };
    }

    /**
     * Get icon for content status.
     */
    public function getStatusIconAttribute(): string
    {
        return match ($this->status) {
            'publish' => 'check-circle',
            'draft' => 'pencil',
            'pending' => 'clock',
            'future' => 'calendar',
            'private' => 'lock-closed',
            default => 'document',
        };
    }

    /**
     * Get Flux badge colour for sync status.
     */
    public function getSyncColorAttribute(): string
    {
        return match ($this->sync_status) {
            'synced' => 'green',
            'pending' => 'yellow',
            'stale' => 'orange',
            'failed' => 'red',
            default => 'zinc',
        };
    }

    /**
     * Get icon for sync status.
     */
    public function getSyncIconAttribute(): string
    {
        return match ($this->sync_status) {
            'synced' => 'check',
            'pending' => 'clock',
            'stale' => 'arrow-path',
            'failed' => 'x-mark',
            default => 'question-mark-circle',
        };
    }

    /**
     * Get Flux badge colour for content type (post/page).
     */
    public function getTypeColorAttribute(): string
    {
        return match ($this->type) {
            'post' => 'blue',
            'page' => 'violet',
            default => 'zinc',
        };
    }

    /**
     * Get Flux badge colour for content source type.
     */
    public function getContentTypeColorAttribute(): string
    {
        return $this->content_type?->color() ?? 'zinc';
    }

    /**
     * Get icon for content source type.
     */
    public function getContentTypeIconAttribute(): string
    {
        return $this->content_type?->icon() ?? 'document';
    }

    /**
     * Get human-readable content source type.
     */
    public function getContentTypeLabelAttribute(): string
    {
        return $this->content_type?->label() ?? 'Unknown';
    }

    /**
     * Get the ContentType enum instance.
     */
    public function getContentTypeEnum(): ?ContentType
    {
        return $this->content_type;
    }

    /**
     * Scope to scheduled content (status = future and publish_at set).
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', 'future')
            ->whereNotNull('publish_at');
    }

    /**
     * Scope to content ready to be published (scheduled time has passed).
     */
    public function scopeReadyToPublish($query)
    {
        return $query->where('status', 'future')
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', now());
    }

    /**
     * Check if this content is scheduled for future publication.
     */
    public function isScheduled(): bool
    {
        return $this->status === 'future' && $this->publish_at !== null;
    }

    // -------------------------------------------------------------------------
    // Preview Links
    // -------------------------------------------------------------------------

    /**
     * Generate a time-limited preview token for sharing unpublished content.
     *
     * @param  int  $hours  Number of hours until expiry (default 24)
     * @return string The generated preview token
     */
    public function generatePreviewToken(int $hours = 24): string
    {
        $token = bin2hex(random_bytes(32));

        $this->update([
            'preview_token' => $token,
            'preview_expires_at' => now()->addHours($hours),
        ]);

        return $token;
    }

    /**
     * Get the preview URL for this content item.
     *
     * Generates a new token if one doesn't exist or has expired.
     *
     * @param  int  $hours  Number of hours until expiry (default 24)
     * @return string The full preview URL
     */
    public function getPreviewUrl(int $hours = 24): string
    {
        // Generate new token if needed
        if (! $this->hasValidPreviewToken()) {
            $this->generatePreviewToken($hours);
        }

        return route('content.preview', [
            'item' => $this->id,
            'token' => $this->preview_token,
        ]);
    }

    /**
     * Check if the current preview token is still valid.
     */
    public function hasValidPreviewToken(): bool
    {
        return $this->preview_token !== null
            && $this->preview_expires_at !== null
            && $this->preview_expires_at->isFuture();
    }

    /**
     * Validate a preview token against this content item.
     */
    public function isValidPreviewToken(?string $token): bool
    {
        if ($token === null || $this->preview_token === null) {
            return false;
        }

        return hash_equals($this->preview_token, $token) && $this->hasValidPreviewToken();
    }

    /**
     * Revoke the current preview token.
     */
    public function revokePreviewToken(): void
    {
        $this->update([
            'preview_token' => null,
            'preview_expires_at' => null,
        ]);
    }

    /**
     * Get remaining time until preview token expires.
     *
     * @return string|null Human-readable time remaining, or null if no valid token
     */
    public function getPreviewTokenTimeRemaining(): ?string
    {
        if (! $this->hasValidPreviewToken()) {
            return null;
        }

        return $this->preview_expires_at->diffForHumans(['parts' => 2]);
    }

    /**
     * Check if this content can be previewed (draft, pending, future, private).
     */
    public function isPreviewable(): bool
    {
        return in_array($this->status, ['draft', 'pending', 'future', 'private']);
    }

    /**
     * Create a revision snapshot of the current state.
     */
    public function createRevision(
        ?User $user = null,
        string $changeType = ContentRevision::CHANGE_EDIT,
        ?string $changeSummary = null
    ): ContentRevision {
        $revision = ContentRevision::createFromContentItem($this, $user, $changeType, $changeSummary);

        // Update revision count
        $this->increment('revision_count');

        return $revision;
    }

    /**
     * Get the latest revision for this content.
     */
    public function latestRevision(): ?ContentRevision
    {
        return $this->revisions()->first();
    }

    /**
     * Convert to array format for frontend rendering.
     *
     * Note: Title and excerpt are plain text and will be escaped by Blade's {{ }}.
     * Content body contains HTML that should be rendered with {!! !!} but is
     * sanitised using HTMLPurifier to prevent XSS.
     */
    public function toRenderArray(): array
    {
        $author = $this->author;
        $featuredMedia = $this->featuredMedia;

        $data = [
            'id' => $this->id,
            'date' => $this->created_at?->toIso8601String(),
            'modified' => $this->updated_at?->toIso8601String(),
            'slug' => $this->slug,
            'status' => $this->status,
            'type' => $this->type,
            'title' => ['rendered' => $this->title], // Plain text - escape with {{ }}
            'content' => ['rendered' => $this->getSanitisedContent(), 'protected' => false],
            'excerpt' => ['rendered' => $this->excerpt], // Plain text - escape with {{ }} or use strip_tags()
            'featured_media' => $this->featured_media_id ?? 0,
        ];

        if ($author) {
            $data['_embedded']['author'] = [[
                'id' => $author->id,
                'name' => $author->name,
                'slug' => $author->slug ?? null,
                'description' => $author->bio ?? null,
                'avatar_urls' => ['96' => $author->avatar_url ?? null],
            ]];
        }

        if ($featuredMedia) {
            $data['_embedded']['wp:featuredmedia'] = [[
                'id' => $featuredMedia->id,
                'source_url' => $featuredMedia->url ?? $featuredMedia->cdn_url ?? $featuredMedia->source_url ?? null,
                'alt_text' => $featuredMedia->alt_text ?? null,
            ]];
        }

        return $data;
    }

    /**
     * Boot method to set default content type.
     */
    protected static function booted(): void
    {
        static::creating(function (ContentItem $item) {
            // Default to native content type for new items
            if ($item->content_type === null) {
                $item->content_type = ContentType::default();
            }
        });
    }
}
