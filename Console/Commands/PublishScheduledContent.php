<?php

declare(strict_types=1);

namespace Core\Mod\Content\Console\Commands;

use Core\Mod\Content\Models\ContentItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * PublishScheduledContent
 *
 * Automatically publishes content items that have a scheduled publish_at
 * date in the past and are still in 'future' status.
 *
 * Run via scheduler every minute to ensure timely publishing.
 */
class PublishScheduledContent extends Command
{
    protected $signature = 'content:publish-scheduled
                            {--dry-run : Show what would be published without making changes}
                            {--limit=100 : Maximum number of items to publish per run}';

    protected $description = 'Publish scheduled content items whose publish_at time has passed';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $query = ContentItem::readyToPublish()->limit($limit);

        $count = $query->count();

        if ($count === 0) {
            $this->info('No scheduled content ready to publish.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d scheduled content item(s)...',
            $dryRun ? 'Would publish' : 'Publishing',
            $count
        ));

        if ($dryRun) {
            $items = $query->get();
            $this->table(
                ['ID', 'Title', 'Workspace', 'Scheduled For'],
                $items->map(fn ($item) => [
                    $item->id,
                    mb_substr($item->title, 0, 50),
                    $item->workspace_id,
                    $item->publish_at->format('Y-m-d H:i:s'),
                ])
            );

            return self::SUCCESS;
        }

        $published = 0;
        $failed = 0;

        $query->each(function (ContentItem $item) use (&$published, &$failed) {
            try {
                $item->update([
                    'status' => 'publish',
                ]);

                Log::info('Auto-published scheduled content', [
                    'content_item_id' => $item->id,
                    'title' => $item->title,
                    'workspace_id' => $item->workspace_id,
                    'scheduled_for' => $item->publish_at?->toIso8601String(),
                ]);

                $published++;
                $this->line("  Published: {$item->title}");
            } catch (\Exception $e) {
                $failed++;
                Log::error('Failed to auto-publish scheduled content', [
                    'content_item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  Failed: {$item->title} - {$e->getMessage()}");
            }
        });

        $this->newLine();
        $this->info("Published: {$published}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
