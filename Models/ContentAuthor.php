<?php

declare(strict_types=1);

namespace Core\Content\Models;

use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentAuthor extends Model
{
    use HasFactory;

    protected static function newFactory(): \Core\Content\Database\Factories\ContentAuthorFactory
    {
        return \Core\Content\Database\Factories\ContentAuthorFactory::new();
    }

    protected $fillable = [
        'workspace_id',
        'wp_id',
        'name',
        'slug',
        'email',
        'avatar_url',
        'bio',
        'social_links',
    ];

    protected $casts = [
        'social_links' => 'array',
    ];

    /**
     * Get the workspace this author belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get all content items by this author.
     */
    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class, 'author_id');
    }

    /**
     * Scope to filter by workspace.
     */
    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    /**
     * Scope to find by WordPress ID.
     */
    public function scopeByWpId($query, int $wpId)
    {
        return $query->where('wp_id', $wpId);
    }
}
