<?php

declare(strict_types=1);

namespace Core\Content\Console\Commands;

use Mod\Agentic\Services\ContentService;
use Illuminate\Console\Command;

class ContentGenerate extends Command
{
    protected $signature = 'content:generate
                            {batch? : Batch ID (e.g., batch-001-link-getting-started)}
                            {--provider=gemini : AI provider (gemini for bulk, claude for refinement)}
                            {--refine : Refine existing drafts using Claude}
                            {--dry-run : Show what would be generated without creating files}
                            {--article= : Generate only a specific article by slug}';

    protected $description = 'Generate content from batch specifications';

    public function __construct(
        protected ContentService $batchService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $batchId = $this->argument('batch');
        $provider = $this->option('provider');
        $refine = $this->option('refine');
        $dryRun = $this->option('dry-run');
        $articleSlug = $this->option('article');

        if (! $batchId) {
            return $this->listBatches();
        }

        if ($refine) {
            return $this->refineBatch($batchId, $dryRun);
        }

        return $this->generateBatch($batchId, $provider, $dryRun, $articleSlug);
    }

    protected function listBatches(): int
    {
        $batches = $this->batchService->listBatches();

        if (empty($batches)) {
            $this->error('No batch specifications found in doc/phase42/tasks/');

            return self::FAILURE;
        }

        $this->info('Available content batches:');
        $this->newLine();

        $this->table(
            ['Batch ID', 'Service', 'Category', 'Articles', 'Priority'],
            array_map(fn ($b) => [
                $b['id'],
                $b['service'],
                $b['category'],
                $b['article_count'],
                $b['priority'],
            ], $batches)
        );

        $this->newLine();
        $this->line('Usage: <info>php artisan content:generate batch-001-link-getting-started</info>');

        return self::SUCCESS;
    }

    protected function generateBatch(string $batchId, string $provider, bool $dryRun, ?string $articleSlug): int
    {
        $this->info("Generating content for batch: <comment>{$batchId}</comment>");
        $this->line("Provider: <comment>{$provider}</comment>");

        if ($dryRun) {
            $this->warn('Dry run mode - no files will be created');
        }

        $this->newLine();

        // Get batch status first
        $status = $this->batchService->getBatchStatus($batchId);

        if (isset($status['error'])) {
            $this->error($status['error']);

            return self::FAILURE;
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total articles', $status['total']],
                ['Already drafted', $status['drafted']],
                ['Remaining', $status['remaining']],
            ]
        );

        if ($status['remaining'] === 0 && ! $articleSlug) {
            $this->info('All articles in this batch have been drafted.');

            return self::SUCCESS;
        }

        $this->newLine();

        if (! $dryRun && ! $this->confirm('Proceed with generation?', true)) {
            $this->line('Cancelled.');

            return self::SUCCESS;
        }

        $this->newLine();
        $results = $this->batchService->generateBatch($batchId, $provider, $dryRun);

        if (isset($results['error'])) {
            $this->error($results['error']);

            return self::FAILURE;
        }

        // Display results
        $this->info('Generation Results:');

        foreach ($results['articles'] as $slug => $result) {
            $statusIcon = match ($result['status']) {
                'generated' => '<fg=green>✓</>',
                'skipped' => '<fg=yellow>-</>',
                'would_generate' => '<fg=blue>?</>',
                'failed' => '<fg=red>✗</>',
            };

            $message = match ($result['status']) {
                'generated' => "Generated: {$result['path']}",
                'skipped' => "Skipped: {$result['reason']}",
                'would_generate' => "Would generate: {$result['path']}",
                'failed' => "Failed: {$result['error']}",
            };

            $this->line("  {$statusIcon} <comment>{$slug}</comment> - {$message}");
        }

        $this->newLine();
        $this->table(
            ['Generated', 'Skipped', 'Failed'],
            [[$results['generated'], $results['skipped'], $results['failed']]]
        );

        return $results['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function refineBatch(string $batchId, bool $dryRun): int
    {
        $this->info("Refining drafts for batch: <comment>{$batchId}</comment>");
        $this->line('Using: <comment>Claude</comment> for quality refinement');

        if ($dryRun) {
            $this->warn('Dry run mode - no files will be modified');
        }

        $this->newLine();

        $spec = $this->batchService->loadBatch($batchId);

        if (! $spec) {
            $this->error("Batch not found: {$batchId}");

            return self::FAILURE;
        }

        $refined = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($spec['articles'] ?? [] as $article) {
            $slug = $article['slug'] ?? null;
            if (! $slug) {
                continue;
            }

            // Find draft file
            $draftPath = $this->findDraft($slug);

            if (! $draftPath) {
                $this->line("  <fg=yellow>-</> <comment>{$slug}</comment> - No draft found");
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("  <fg=blue>?</> <comment>{$slug}</comment> - Would refine: {$draftPath}");

                continue;
            }

            try {
                $refinedContent = $this->batchService->refineDraft($draftPath);

                // Create backup
                copy($draftPath, $draftPath.'.backup');

                // Write refined content
                file_put_contents($draftPath, $refinedContent);

                $this->line("  <fg=green>✓</> <comment>{$slug}</comment> - Refined");
                $refined++;
            } catch (\Exception $e) {
                $this->line("  <fg=red>✗</> <comment>{$slug}</comment> - {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->table(
            ['Refined', 'Skipped', 'Failed'],
            [[$refined, $skipped, $failed]]
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function findDraft(string $slug): ?string
    {
        $basePath = base_path('doc/phase42/drafts');
        $patterns = [
            "{$basePath}/help/**/{$slug}.md",
            "{$basePath}/blog/**/{$slug}.md",
            "{$basePath}/**/{$slug}.md",
        ];

        foreach ($patterns as $pattern) {
            $matches = glob($pattern);
            if (! empty($matches)) {
                return $matches[0];
            }
        }

        return null;
    }
}
