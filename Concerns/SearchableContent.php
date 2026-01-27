<?php

declare(strict_types=1);

namespace Core\Mod\Content\Concerns;

/**
 * Trait for making ContentItem searchable with Laravel Scout.
 *
 * This trait should be added to ContentItem when Laravel Scout is installed.
 * It provides:
 * - Searchable array definition for indexing
 * - Custom index name per workspace
 * - Filtering configuration for Meilisearch
 *
 * Usage:
 * 1. Install Laravel Scout: composer require laravel/scout
 * 2. Add this trait to ContentItem model
 * 3. Configure search backend in config/content.php
 *
 * @see https://laravel.com/docs/scout
 */
trait SearchableContent
{
    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'content' => $this->getSearchableContent(),
            'type' => $this->type,
            'status' => $this->status,
            'content_type' => $this->content_type?->value,
            'author_id' => $this->author_id,
            'author_name' => $this->author?->name,
            'categories' => $this->categories->pluck('slug')->all(),
            'tags' => $this->tags->pluck('slug')->all(),
            'created_at' => $this->created_at?->timestamp,
            'updated_at' => $this->updated_at?->timestamp,
            'publish_at' => $this->publish_at?->timestamp,
        ];
    }

    /**
     * Get searchable content text (HTML stripped).
     */
    protected function getSearchableContent(): string
    {
        $content = $this->content_markdown
            ?? $this->content_html
            ?? $this->content_html_clean
            ?? '';

        // Strip HTML tags and normalise whitespace
        $text = strip_tags($content);
        $text = preg_replace('/\s+/', ' ', $text);

        // Limit content length for indexing (Scout/Meilisearch has limits)
        return mb_substr(trim($text), 0, 50000);
    }

    /**
     * Get the name of the index associated with the model.
     *
     * Using workspace-prefixed index allows for tenant isolation.
     */
    public function searchableAs(): string
    {
        $prefix = config('scout.prefix', '');
        $workspaceId = $this->workspace_id ?? 'global';

        return "{$prefix}content_items_{$workspaceId}";
    }

    /**
     * Determine if the model should be searchable.
     *
     * Only index native content types (not WordPress legacy content).
     */
    public function shouldBeSearchable(): bool
    {
        // Only index native content
        if ($this->content_type && ! $this->content_type->isNative()) {
            return false;
        }

        // Don't index trashed content
        if ($this->trashed()) {
            return false;
        }

        return true;
    }

    /**
     * Get filterable attributes for Meilisearch.
     *
     * These attributes can be used in filter queries.
     *
     * @return array<string>
     */
    public static function getFilterableAttributes(): array
    {
        return [
            'workspace_id',
            'type',
            'status',
            'content_type',
            'author_id',
            'categories',
            'tags',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * Get sortable attributes for Meilisearch.
     *
     * @return array<string>
     */
    public static function getSortableAttributes(): array
    {
        return [
            'created_at',
            'updated_at',
            'publish_at',
            'title',
        ];
    }

    /**
     * Modify the search query builder before executing.
     *
     * @param  \Laravel\Scout\Builder  $query
     * @return \Laravel\Scout\Builder
     */
    public function modifyScoutQuery($query, string $search)
    {
        return $query;
    }
}
