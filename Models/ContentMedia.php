<?php

declare(strict_types=1);

namespace Core\Mod\Content\Models;

use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentMedia extends Model
{
    use HasFactory;

    protected static function newFactory(): \Core\Mod\Content\Database\Factories\ContentMediaFactory
    {
        return \Core\Mod\Content\Database\Factories\ContentMediaFactory::new();
    }

    protected $table = 'content_media';

    protected $fillable = [
        'workspace_id',
        'wp_id',
        'title',
        'filename',
        'mime_type',
        'file_size',
        'source_url',
        'cdn_url',
        'width',
        'height',
        'alt_text',
        'caption',
        'sizes',
    ];

    protected $casts = [
        'sizes' => 'array',
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    /**
     * Get the workspace this media belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the best URL for this media (CDN if available, otherwise source).
     */
    public function getUrlAttribute(): string
    {
        return $this->cdn_url ?: $this->source_url;
    }

    /**
     * Get URL for a specific size.
     */
    public function getSizeUrl(string $size): ?string
    {
        if (! $this->sizes || ! isset($this->sizes[$size])) {
            return null;
        }

        return $this->sizes[$size]['source_url'] ?? null;
    }

    /**
     * Check if this is an image.
     */
    public function getIsImageAttribute(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Scope to filter by workspace.
     */
    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    /**
     * Scope to only images.
     */
    public function scopeImages($query)
    {
        return $query->where('mime_type', 'like', 'image/%');
    }

    /**
     * Scope to find by WordPress ID.
     */
    public function scopeByWpId($query, int $wpId)
    {
        return $query->where('wp_id', $wpId);
    }
}
