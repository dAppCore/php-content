<?php

declare(strict_types=1);

namespace Core\Mod\Content\Console\Commands;

use Core\Mod\Content\Models\ContentRevision;
use Illuminate\Console\Command;

/**
 * Prune old content revisions based on retention policy.
 *
 * Removes revisions that exceed the configured limits:
 * - Maximum revisions per content item (default: 50)
 * - Maximum age in days (default: 180)
 *
 * Published revisions are preserved by default.
 */
class PruneContentRevisions extends Command
{
    protected $signature = 'content:prune-revisions
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--max-revisions= : Override maximum revisions per item}
                            {--max-age= : Override maximum age in days}';

    protected $description = 'Prune old content revisions based on retention policy';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $maxRevisions = $this->option('max-revisions')
            ? (int) $this->option('max-revisions')
            : config('content.revisions.max_per_item', 50);
        $maxAgeDays = $this->option('max-age')
            ? (int) $this->option('max-age')
            : config('content.revisions.max_age_days', 180);

        $this->info('Content Revision Pruning');
        $this->info('========================');
        $this->newLine();
        $this->line("Max revisions per item: {$maxRevisions}");
        $this->line("Max age (days): {$maxAgeDays}");

        if ($dryRun) {
            $this->warn('DRY RUN - No changes will be made');
            $this->newLine();
        }

        // Get statistics before pruning
        $totalRevisions = ContentRevision::count();
        $contentItemIds = ContentRevision::distinct()->pluck('content_item_id');

        $this->line("Total revisions: {$totalRevisions}");
        $this->line("Content items with revisions: {$contentItemIds->count()}");
        $this->newLine();

        if ($dryRun) {
            // Calculate what would be deleted
            $wouldDelete = 0;

            foreach ($contentItemIds as $contentItemId) {
                $count = $this->countPrunableRevisions($contentItemId, $maxRevisions, $maxAgeDays);
                $wouldDelete += $count;
            }

            $this->info("Would delete: {$wouldDelete} revisions");

            return self::SUCCESS;
        }

        // Perform the pruning
        $this->output->write('Pruning revisions... ');

        $result = ContentRevision::pruneAll();

        $this->info('Done');
        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Content items processed', $result['items_processed']],
                ['Revisions deleted', $result['revisions_deleted']],
                ['Revisions remaining', ContentRevision::count()],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Count revisions that would be pruned for a content item.
     */
    protected function countPrunableRevisions(int $contentItemId, int $maxRevisions, int $maxAgeDays): int
    {
        $count = 0;

        // Count revisions older than max age
        if ($maxAgeDays > 0) {
            $count += ContentRevision::where('content_item_id', $contentItemId)
                ->where('change_type', '!=', ContentRevision::CHANGE_PUBLISH)
                ->where('created_at', '<', now()->subDays($maxAgeDays))
                ->count();
        }

        // Count excess revisions
        if ($maxRevisions > 0) {
            $total = ContentRevision::where('content_item_id', $contentItemId)->count();
            if ($total > $maxRevisions) {
                // This is approximate - actual count depends on overlap with age-based deletions
                $count += max(0, $total - $maxRevisions);
            }
        }

        return $count;
    }
}
