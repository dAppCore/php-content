<?php

declare(strict_types=1);

namespace Core\Mod\Content\Models;

use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ContentTaxonomy extends Model
{
    use HasFactory;

    protected static function newFactory(): \Core\Mod\Content\Database\Factories\ContentTaxonomyFactory
    {
        return \Core\Mod\Content\Database\Factories\ContentTaxonomyFactory::new();
    }

    protected $fillable = [
        'workspace_id',
        'wp_id',
        'type',
        'name',
        'slug',
        'description',
        'parent_wp_id',
        'count',
    ];

    protected $casts = [
        'count' => 'integer',
    ];

    /**
     * Get the workspace this taxonomy belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get content items with this taxonomy.
     */
    public function contentItems(): BelongsToMany
    {
        return $this->belongsToMany(ContentItem::class, 'content_item_taxonomy')
            ->withTimestamps();
    }

    /**
     * Get the parent taxonomy.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_wp_id', 'wp_id')
            ->where('workspace_id', $this->workspace_id);
    }

    /**
     * Scope to filter by workspace.
     */
    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    /**
     * Scope to only categories.
     */
    public function scopeCategories($query)
    {
        return $query->where('type', 'category');
    }

    /**
     * Scope to only tags.
     */
    public function scopeTags($query)
    {
        return $query->where('type', 'tag');
    }

    /**
     * Scope to find by WordPress ID.
     */
    public function scopeByWpId($query, int $wpId)
    {
        return $query->where('wp_id', $wpId);
    }
}
