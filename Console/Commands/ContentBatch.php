<?php

declare(strict_types=1);

namespace Core\Content\Console\Commands;

use Mod\Agentic\Services\ContentService;
use Illuminate\Console\Command;

class ContentBatch extends Command
{
    protected $signature = 'content:batch
                            {action=list : Action: list, status, schedule}
                            {batch? : Batch ID for status/schedule actions}';

    protected $description = 'Manage content generation batches';

    public function __construct(
        protected ContentService $batchService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = $this->argument('action');
        $batchId = $this->argument('batch');

        return match ($action) {
            'list' => $this->listBatches(),
            'status' => $this->showStatus($batchId),
            'schedule' => $this->showSchedule(),
            default => $this->showHelp(),
        };
    }

    protected function listBatches(): int
    {
        $this->info('Content Generation Batches');
        $this->newLine();

        $batches = $this->batchService->listBatches();

        if (empty($batches)) {
            $this->warn('No batch specifications found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Batch ID', 'Service', 'Category', 'Articles', 'Priority'],
            array_map(fn ($b) => [
                $b['id'],
                $b['service'],
                $b['category'],
                $b['article_count'],
                ucfirst($b['priority']),
            ], $batches)
        );

        $totalArticles = array_sum(array_column($batches, 'article_count'));
        $this->newLine();
        $this->line('Total batches: <info>'.count($batches).'</info>');
        $this->line("Total articles: <info>{$totalArticles}</info>");

        return self::SUCCESS;
    }

    protected function showStatus(?string $batchId = null): int
    {
        if (! $batchId) {
            return $this->showAllStatuses();
        }

        $status = $this->batchService->getBatchStatus($batchId);

        if (isset($status['error'])) {
            $this->error($status['error']);

            return self::FAILURE;
        }

        $this->info("Batch Status: <comment>{$batchId}</comment>");
        $this->newLine();

        $this->table(
            ['Metric', 'Count', 'Percentage'],
            [
                ['Total articles', $status['total'], '100%'],
                ['Drafted', $status['drafted'], $this->percentage($status['drafted'], $status['total'])],
                ['Generated', $status['generated'], $this->percentage($status['generated'], $status['total'])],
                ['Published', $status['published'], $this->percentage($status['published'], $status['total'])],
                ['Remaining', $status['remaining'], $this->percentage($status['remaining'], $status['total'])],
            ]
        );

        // Progress bar
        $progress = $status['total'] > 0
            ? round(($status['drafted'] / $status['total']) * 100)
            : 0;

        $this->newLine();
        $this->line('Progress: '.$this->progressBar($progress));

        return self::SUCCESS;
    }

    protected function showAllStatuses(): int
    {
        $this->info('Batch Status Overview');
        $this->newLine();

        $batches = $this->batchService->listBatches();
        $rows = [];
        $totals = ['total' => 0, 'drafted' => 0, 'published' => 0];

        foreach ($batches as $batch) {
            $status = $this->batchService->getBatchStatus($batch['id']);

            if (isset($status['error'])) {
                continue;
            }

            $progress = $status['total'] > 0
                ? round(($status['drafted'] / $status['total']) * 100)
                : 0;

            $rows[] = [
                $batch['id'],
                $status['drafted'].'/'.$status['total'],
                $status['published'],
                $this->progressBar($progress, 10),
            ];

            $totals['total'] += $status['total'];
            $totals['drafted'] += $status['drafted'];
            $totals['published'] += $status['published'];
        }

        $this->table(
            ['Batch', 'Drafted', 'Published', 'Progress'],
            $rows
        );

        $this->newLine();
        $overallProgress = $totals['total'] > 0
            ? round(($totals['drafted'] / $totals['total']) * 100)
            : 0;

        $this->line("Overall: <info>{$totals['drafted']}/{$totals['total']}</info> articles drafted ({$overallProgress}%)");
        $this->line("Published: <info>{$totals['published']}</info> articles live");

        return self::SUCCESS;
    }

    protected function showSchedule(): int
    {
        $this->info('Content Generation Schedule (Phase 42)');
        $this->newLine();

        // Read schedule from task index
        $taskIndexPath = base_path('doc/phase42/tasks/00-task-index.md');

        if (! file_exists($taskIndexPath)) {
            $this->warn('Task index not found.');

            return self::FAILURE;
        }

        $content = file_get_contents($taskIndexPath);

        // Extract weekly schedule
        if (preg_match('/## Weekly Schedule(.+?)(?=## |$)/s', $content, $match)) {
            $schedule = $match[1];

            // Parse weeks
            preg_match_all('/### (Week \d+)\n(.+?)(?=### Week|\Z)/s', $schedule, $weeks);

            foreach ($weeks[1] as $i => $week) {
                $this->line("<info>{$week}</info>");

                // Parse tasks in week
                $tasks = $weeks[2][$i];
                preg_match_all('/- \[([ x])\] (.+)/', $tasks, $items);

                foreach ($items[1] as $j => $status) {
                    $icon = $status === 'x' ? '<fg=green>✓</>' : '<fg=yellow>○</>';
                    $this->line("  {$icon} {$items[2][$j]}");
                }

                $this->newLine();
            }
        } else {
            $this->warn('Could not parse weekly schedule from task index.');
        }

        return self::SUCCESS;
    }

    protected function showHelp(): int
    {
        $this->info('Content Batch Management');
        $this->newLine();
        $this->line('Actions:');
        $this->line('  <info>list</info>      - List all available batches');
        $this->line('  <info>status</info>    - Show status for all batches or a specific batch');
        $this->line('  <info>schedule</info>  - Show the generation schedule');
        $this->newLine();
        $this->line('Examples:');
        $this->line('  php artisan content:batch list');
        $this->line('  php artisan content:batch status batch-001-link-getting-started');
        $this->line('  php artisan content:batch schedule');

        return self::SUCCESS;
    }

    protected function percentage(int $value, int $total): string
    {
        if ($total === 0) {
            return '0%';
        }

        return round(($value / $total) * 100).'%';
    }

    protected function progressBar(int $percent, int $width = 20): string
    {
        $filled = (int) round($percent / 100 * $width);
        $empty = $width - $filled;

        $bar = str_repeat('█', $filled).str_repeat('░', $empty);

        $colour = match (true) {
            $percent >= 75 => 'green',
            $percent >= 50 => 'yellow',
            $percent >= 25 => 'orange',
            default => 'red',
        };

        return "<fg={$colour}>{$bar}</> {$percent}%";
    }
}
