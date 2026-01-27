<?php

declare(strict_types=1);

namespace Core\Mod\Content\Models;

use Core\Mod\Tenant\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ContentRevision - Stores version history for content items.
 *
 * Each revision is an immutable snapshot of content at a point in time.
 * Used for:
 * - Viewing change history
 * - Comparing versions
 * - Restoring previous versions
 * - Audit trail
 */
class ContentRevision extends Model
{
    use HasFactory;

    /**
     * Change types for revision tracking.
     */
    public const CHANGE_EDIT = 'edit';

    public const CHANGE_AUTOSAVE = 'autosave';

    public const CHANGE_RESTORE = 'restore';

    public const CHANGE_PUBLISH = 'publish';

    public const CHANGE_UNPUBLISH = 'unpublish';

    public const CHANGE_SCHEDULE = 'schedule';

    protected $fillable = [
        'content_item_id',
        'user_id',
        'revision_number',
        'title',
        'excerpt',
        'content_html',
        'content_markdown',
        'content_json',
        'editor_state',
        'seo_meta',
        'status',
        'change_type',
        'change_summary',
        'word_count',
        'char_count',
    ];

    protected $casts = [
        'content_json' => 'array',
        'editor_state' => 'array',
        'seo_meta' => 'array',
        'revision_number' => 'integer',
        'word_count' => 'integer',
        'char_count' => 'integer',
    ];

    /**
     * Get the content item this revision belongs to.
     */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /**
     * Get the user who made this revision.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to get revisions in reverse chronological order.
     */
    public function scopeLatestFirst($query)
    {
        return $query->orderByDesc('revision_number');
    }

    /**
     * Scope to get revisions for a specific content item.
     */
    public function scopeForContentItem($query, int $contentItemId)
    {
        return $query->where('content_item_id', $contentItemId);
    }

    /**
     * Scope to exclude autosaves (for cleaner history view).
     */
    public function scopeWithoutAutosaves($query)
    {
        return $query->where('change_type', '!=', self::CHANGE_AUTOSAVE);
    }

    /**
     * Create a revision from a ContentItem.
     */
    public static function createFromContentItem(
        ContentItem $item,
        ?User $user = null,
        string $changeType = self::CHANGE_EDIT,
        ?string $changeSummary = null
    ): self {
        $nextRevision = static::where('content_item_id', $item->id)->max('revision_number') + 1;

        // Calculate word/char counts
        $plainText = strip_tags($item->content_html ?? $item->content_markdown ?? '');
        $wordCount = str_word_count($plainText);
        $charCount = mb_strlen($plainText);

        return static::create([
            'content_item_id' => $item->id,
            'user_id' => $user?->id,
            'revision_number' => $nextRevision,
            'title' => $item->title,
            'excerpt' => $item->excerpt,
            'content_html' => $item->content_html,
            'content_markdown' => $item->content_markdown,
            'content_json' => $item->content_json,
            'editor_state' => $item->editor_state,
            'seo_meta' => $item->seo_meta,
            'status' => $item->status,
            'change_type' => $changeType,
            'change_summary' => $changeSummary,
            'word_count' => $wordCount,
            'char_count' => $charCount,
        ]);
    }

    /**
     * Restore this revision to the content item.
     */
    public function restoreToContentItem(): ContentItem
    {
        $item = $this->contentItem;

        $item->update([
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'content_html' => $this->content_html,
            'content_markdown' => $this->content_markdown,
            'content_json' => $this->content_json,
            'editor_state' => $this->editor_state,
            'seo_meta' => $this->seo_meta,
        ]);

        // Create a new revision marking the restore
        static::createFromContentItem(
            $item,
            auth()->user(),
            self::CHANGE_RESTORE,
            "Restored from revision #{$this->revision_number}"
        );

        return $item->fresh();
    }

    /**
     * Get human-readable change type label.
     */
    public function getChangeTypeLabelAttribute(): string
    {
        return match ($this->change_type) {
            self::CHANGE_EDIT => 'Edited',
            self::CHANGE_AUTOSAVE => 'Auto-saved',
            self::CHANGE_RESTORE => 'Restored',
            self::CHANGE_PUBLISH => 'Published',
            self::CHANGE_UNPUBLISH => 'Unpublished',
            self::CHANGE_SCHEDULE => 'Scheduled',
            default => ucfirst($this->change_type),
        };
    }

    /**
     * Get Flux badge colour for change type.
     */
    public function getChangeTypeColorAttribute(): string
    {
        return match ($this->change_type) {
            self::CHANGE_EDIT => 'blue',
            self::CHANGE_AUTOSAVE => 'zinc',
            self::CHANGE_RESTORE => 'orange',
            self::CHANGE_PUBLISH => 'green',
            self::CHANGE_UNPUBLISH => 'yellow',
            self::CHANGE_SCHEDULE => 'violet',
            default => 'zinc',
        };
    }

    /**
     * Get a diff summary comparing to previous revision.
     */
    public function getDiffSummary(): ?array
    {
        $previous = static::where('content_item_id', $this->content_item_id)
            ->where('revision_number', $this->revision_number - 1)
            ->first();

        if (! $previous) {
            return null;
        }

        return [
            'title_changed' => $this->title !== $previous->title,
            'excerpt_changed' => $this->excerpt !== $previous->excerpt,
            'content_changed' => $this->content_html !== $previous->content_html,
            'status_changed' => $this->status !== $previous->status,
            'seo_changed' => $this->seo_meta !== $previous->seo_meta,
            'word_diff' => $this->word_count - $previous->word_count,
            'char_diff' => $this->char_count - $previous->char_count,
        ];
    }

    /**
     * Get actual text diff comparing to another revision.
     *
     * Returns an array with 'title', 'excerpt', and 'content' diffs.
     * Each diff contains 'old', 'new', and 'changes' (inline diff markup).
     */
    public function getDiff(?self $compareWith = null): array
    {
        // Default to previous revision if none specified
        if ($compareWith === null) {
            $compareWith = static::where('content_item_id', $this->content_item_id)
                ->where('revision_number', $this->revision_number - 1)
                ->first();
        }

        $result = [
            'has_previous' => $compareWith !== null,
            'from_revision' => $compareWith?->revision_number,
            'to_revision' => $this->revision_number,
            'title' => $this->computeFieldDiff(
                $compareWith?->title ?? '',
                $this->title ?? ''
            ),
            'excerpt' => $this->computeFieldDiff(
                $compareWith?->excerpt ?? '',
                $this->excerpt ?? ''
            ),
            'content' => $this->computeContentDiff(
                $compareWith?->content_html ?? $compareWith?->content_markdown ?? '',
                $this->content_html ?? $this->content_markdown ?? ''
            ),
            'status' => [
                'old' => $compareWith?->status,
                'new' => $this->status,
                'changed' => $compareWith?->status !== $this->status,
            ],
            'word_count' => [
                'old' => $compareWith?->word_count ?? 0,
                'new' => $this->word_count ?? 0,
                'diff' => ($this->word_count ?? 0) - ($compareWith?->word_count ?? 0),
            ],
        ];

        return $result;
    }

    /**
     * Compute diff for a simple text field.
     */
    protected function computeFieldDiff(string $old, string $new): array
    {
        $changed = $old !== $new;

        return [
            'old' => $old,
            'new' => $new,
            'changed' => $changed,
            'inline' => $changed ? $this->generateInlineDiff($old, $new) : $new,
        ];
    }

    /**
     * Compute diff for content (HTML/Markdown).
     *
     * Strips HTML tags for comparison to focus on text changes.
     */
    protected function computeContentDiff(string $old, string $new): array
    {
        // Strip HTML for cleaner text comparison
        $oldText = strip_tags($old);
        $newText = strip_tags($new);

        $changed = $oldText !== $newText;

        return [
            'old' => $old,
            'new' => $new,
            'old_text' => $oldText,
            'new_text' => $newText,
            'changed' => $changed,
            'lines' => $changed ? $this->generateLineDiff($oldText, $newText) : [],
        ];
    }

    /**
     * Generate inline diff markup for short text.
     *
     * Uses a simple word-level diff algorithm.
     */
    protected function generateInlineDiff(string $old, string $new): string
    {
        if (empty($old)) {
            return '<ins class="diff-added">'.$new.'</ins>';
        }

        if (empty($new)) {
            return '<del class="diff-removed">'.$old.'</del>';
        }

        $oldWords = preg_split('/(\s+)/', $old, -1, PREG_SPLIT_DELIM_CAPTURE);
        $newWords = preg_split('/(\s+)/', $new, -1, PREG_SPLIT_DELIM_CAPTURE);

        $diff = $this->computeLcs($oldWords, $newWords);

        return $this->formatInlineDiff($diff);
    }

    /**
     * Generate line-by-line diff for longer content.
     */
    protected function generateLineDiff(string $old, string $new): array
    {
        $oldLines = explode("\n", $old);
        $newLines = explode("\n", $new);

        $diff = [];
        $maxLines = max(count($oldLines), count($newLines));

        // Simple line-by-line comparison
        $oldIndex = 0;
        $newIndex = 0;

        while ($oldIndex < count($oldLines) || $newIndex < count($newLines)) {
            $oldLine = $oldLines[$oldIndex] ?? null;
            $newLine = $newLines[$newIndex] ?? null;

            if ($oldLine === $newLine) {
                // Unchanged line
                $diff[] = [
                    'type' => 'unchanged',
                    'content' => $newLine,
                    'line_old' => $oldIndex + 1,
                    'line_new' => $newIndex + 1,
                ];
                $oldIndex++;
                $newIndex++;
            } elseif ($oldLine !== null && ! in_array($oldLine, array_slice($newLines, $newIndex), true)) {
                // Line removed (not found in remaining new lines)
                $diff[] = [
                    'type' => 'removed',
                    'content' => $oldLine,
                    'line_old' => $oldIndex + 1,
                    'line_new' => null,
                ];
                $oldIndex++;
            } elseif ($newLine !== null && ! in_array($newLine, array_slice($oldLines, $oldIndex), true)) {
                // Line added (not found in remaining old lines)
                $diff[] = [
                    'type' => 'added',
                    'content' => $newLine,
                    'line_old' => null,
                    'line_new' => $newIndex + 1,
                ];
                $newIndex++;
            } else {
                // Line modified - show both
                if ($oldLine !== null) {
                    $diff[] = [
                        'type' => 'removed',
                        'content' => $oldLine,
                        'line_old' => $oldIndex + 1,
                        'line_new' => null,
                    ];
                    $oldIndex++;
                }
                if ($newLine !== null) {
                    $diff[] = [
                        'type' => 'added',
                        'content' => $newLine,
                        'line_old' => null,
                        'line_new' => $newIndex + 1,
                    ];
                    $newIndex++;
                }
            }

            // Safety limit
            if (count($diff) > 1000) {
                $diff[] = [
                    'type' => 'truncated',
                    'content' => '... (diff truncated)',
                    'line_old' => null,
                    'line_new' => null,
                ];
                break;
            }
        }

        return $diff;
    }

    /**
     * Compute Longest Common Subsequence for word diff.
     */
    protected function computeLcs(array $old, array $new): array
    {
        $m = count($old);
        $n = count($new);

        // Build LCS length table
        $lcs = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if ($old[$i - 1] === $new[$j - 1]) {
                    $lcs[$i][$j] = $lcs[$i - 1][$j - 1] + 1;
                } else {
                    $lcs[$i][$j] = max($lcs[$i - 1][$j], $lcs[$i][$j - 1]);
                }
            }
        }

        // Backtrack to find diff
        $diff = [];
        $i = $m;
        $j = $n;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $old[$i - 1] === $new[$j - 1]) {
                array_unshift($diff, ['type' => 'unchanged', 'value' => $old[$i - 1]]);
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $lcs[$i][$j - 1] >= $lcs[$i - 1][$j])) {
                array_unshift($diff, ['type' => 'added', 'value' => $new[$j - 1]]);
                $j--;
            } elseif ($i > 0 && ($j === 0 || $lcs[$i][$j - 1] < $lcs[$i - 1][$j])) {
                array_unshift($diff, ['type' => 'removed', 'value' => $old[$i - 1]]);
                $i--;
            }
        }

        return $diff;
    }

    /**
     * Format LCS diff result as inline HTML.
     */
    protected function formatInlineDiff(array $diff): string
    {
        $result = '';
        $pendingRemoved = '';
        $pendingAdded = '';

        foreach ($diff as $item) {
            if ($item['type'] === 'unchanged') {
                // Flush pending changes
                if ($pendingRemoved !== '') {
                    $result .= '<del class="diff-removed">'.e($pendingRemoved).'</del>';
                    $pendingRemoved = '';
                }
                if ($pendingAdded !== '') {
                    $result .= '<ins class="diff-added">'.e($pendingAdded).'</ins>';
                    $pendingAdded = '';
                }
                $result .= e($item['value']);
            } elseif ($item['type'] === 'removed') {
                $pendingRemoved .= $item['value'];
            } elseif ($item['type'] === 'added') {
                $pendingAdded .= $item['value'];
            }
        }

        // Flush any remaining changes
        if ($pendingRemoved !== '') {
            $result .= '<del class="diff-removed">'.e($pendingRemoved).'</del>';
        }
        if ($pendingAdded !== '') {
            $result .= '<ins class="diff-added">'.e($pendingAdded).'</ins>';
        }

        return $result;
    }

    /**
     * Compare two specific revisions by ID.
     */
    public static function compare(int $fromId, int $toId): array
    {
        $from = static::findOrFail($fromId);
        $to = static::findOrFail($toId);

        return $to->getDiff($from);
    }

    /**
     * Prune old revisions for a content item based on retention policy.
     *
     * @param  int  $contentItemId  The content item to prune revisions for
     * @param  int|null  $maxRevisions  Maximum revisions to keep (null = config default)
     * @param  int|null  $maxAgeDays  Maximum age in days (null = config default)
     * @param  bool  $preservePublished  Whether to preserve published revisions
     * @return int Number of revisions deleted
     */
    public static function pruneForContentItem(
        int $contentItemId,
        ?int $maxRevisions = null,
        ?int $maxAgeDays = null,
        bool $preservePublished = true
    ): int {
        $maxRevisions = $maxRevisions ?? config('content.revisions.max_per_item', 50);
        $maxAgeDays = $maxAgeDays ?? config('content.revisions.max_age_days', 180);
        $preservePublished = $preservePublished && config('content.revisions.preserve_published', true);

        $deleted = 0;

        // Build base query for deletable revisions
        $baseQuery = static::where('content_item_id', $contentItemId);

        if ($preservePublished) {
            $baseQuery->where('change_type', '!=', self::CHANGE_PUBLISH);
        }

        // Delete revisions older than max age
        if ($maxAgeDays > 0) {
            $ageDeleted = (clone $baseQuery)
                ->where('created_at', '<', now()->subDays($maxAgeDays))
                ->delete();
            $deleted += $ageDeleted;
        }

        // Delete excess revisions beyond max count (keep most recent)
        if ($maxRevisions > 0) {
            $totalRevisions = static::where('content_item_id', $contentItemId)->count();

            if ($totalRevisions > $maxRevisions) {
                // Get IDs of revisions to keep (most recent ones)
                $keepIds = static::where('content_item_id', $contentItemId)
                    ->orderByDesc('revision_number')
                    ->take($maxRevisions)
                    ->pluck('id');

                // Also keep any published revisions if preserving
                if ($preservePublished) {
                    $publishedIds = static::where('content_item_id', $contentItemId)
                        ->where('change_type', self::CHANGE_PUBLISH)
                        ->pluck('id');
                    $keepIds = $keepIds->merge($publishedIds)->unique();
                }

                // Delete everything not in the keep list
                $countDeleted = static::where('content_item_id', $contentItemId)
                    ->whereNotIn('id', $keepIds)
                    ->delete();
                $deleted += $countDeleted;
            }
        }

        return $deleted;
    }

    /**
     * Prune revisions for all content items based on retention policy.
     *
     * @return array{items_processed: int, revisions_deleted: int}
     */
    public static function pruneAll(): array
    {
        $maxRevisions = config('content.revisions.max_per_item', 50);
        $maxAgeDays = config('content.revisions.max_age_days', 180);

        // Skip if no limits configured
        if ($maxRevisions <= 0 && $maxAgeDays <= 0) {
            return ['items_processed' => 0, 'revisions_deleted' => 0];
        }

        $itemsProcessed = 0;
        $totalDeleted = 0;

        // Get all content items with revisions
        $contentItemIds = static::distinct()->pluck('content_item_id');

        foreach ($contentItemIds as $contentItemId) {
            $deleted = static::pruneForContentItem($contentItemId);
            if ($deleted > 0) {
                $totalDeleted += $deleted;
            }
            $itemsProcessed++;
        }

        return [
            'items_processed' => $itemsProcessed,
            'revisions_deleted' => $totalDeleted,
        ];
    }
}
