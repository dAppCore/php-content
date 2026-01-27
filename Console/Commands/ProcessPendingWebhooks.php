<?php

declare(strict_types=1);

namespace Core\Mod\Content\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Core\Mod\Content\Services\WebhookRetryService;

/**
 * ProcessPendingWebhooks
 *
 * Processes webhooks that are pending retry using exponential backoff.
 * Designed to run every minute via the scheduler.
 *
 * Backoff intervals: 1m, 5m, 15m, 1h, 4h
 * Max retries: 5 (default, configurable per webhook)
 */
class ProcessPendingWebhooks extends Command
{
    protected $signature = 'content:process-webhooks
                            {--batch=50 : Maximum number of webhooks to process per run}
                            {--dry-run : Show what would be processed without making changes}';

    protected $description = 'Process pending webhook retries with exponential backoff';

    public function handle(WebhookRetryService $service): int
    {
        $batchSize = (int) $this->option('batch');
        $dryRun = $this->option('dry-run');

        $webhooks = $service->getRetryableWebhooks($batchSize);
        $count = $webhooks->count();

        if ($count === 0) {
            $this->info('No pending webhooks to process.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d webhook(s)...',
            $dryRun ? 'Would process' : 'Processing',
            $count
        ));

        if ($dryRun) {
            $this->table(
                ['ID', 'Event', 'Workspace', 'Retry #', 'Scheduled For', 'Last Error'],
                $webhooks->map(fn ($wh) => [
                    $wh->id,
                    $wh->event_type,
                    $wh->workspace_id ?? 'N/A',
                    $wh->retry_count + 1,
                    $wh->next_retry_at?->format('Y-m-d H:i:s'),
                    mb_substr($wh->last_error ?? '-', 0, 40),
                ])
            );

            return self::SUCCESS;
        }

        $succeeded = 0;
        $failed = 0;

        $this->withProgressBar($webhooks, function ($webhook) use ($service, &$succeeded, &$failed) {
            $result = $service->retry($webhook);

            if ($result) {
                $succeeded++;
            } else {
                $failed++;
            }
        });

        $this->newLine(2);
        $this->info("Processed: {$count}, Succeeded: {$succeeded}, Failed: {$failed}");

        // Log summary
        Log::info('Webhook retry batch completed', [
            'processed' => $count,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'pending_remaining' => $service->countPendingRetries(),
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
