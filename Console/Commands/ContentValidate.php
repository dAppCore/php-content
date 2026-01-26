<?php

declare(strict_types=1);

namespace Core\Content\Console\Commands;

use Mod\Agentic\Services\ContentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ContentValidate extends Command
{
    protected $signature = 'content:validate
                            {batch? : Batch ID to validate (or "all" for all drafts)}
                            {--fix : Attempt to auto-fix simple issues}
                            {--strict : Fail on warnings as well as errors}';

    protected $description = 'Validate content drafts against quality gates';

    public function __construct(
        protected ContentService $batchService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $batchId = $this->argument('batch');
        $fix = $this->option('fix');
        $strict = $this->option('strict');

        if (! $batchId) {
            return $this->showUsage();
        }

        if ($batchId === 'all') {
            return $this->validateAllDrafts($fix, $strict);
        }

        return $this->validateBatch($batchId, $fix, $strict);
    }

    protected function showUsage(): int
    {
        $this->info('Content Validation Tool');
        $this->newLine();
        $this->line('Usage:');
        $this->line('  <info>php artisan content:validate batch-001</info>  - Validate specific batch');
        $this->line('  <info>php artisan content:validate all</info>        - Validate all drafts');
        $this->line('  <info>php artisan content:validate all --fix</info>  - Auto-fix simple issues');
        $this->newLine();

        // Show available batches
        $batches = $this->batchService->listBatches();
        if (! empty($batches)) {
            $this->info('Available batches:');
            foreach ($batches as $batch) {
                $this->line("  - {$batch['id']}");
            }
        }

        return self::SUCCESS;
    }

    protected function validateBatch(string $batchId, bool $fix, bool $strict): int
    {
        $this->info("Validating batch: <comment>{$batchId}</comment>");
        $this->newLine();

        $spec = $this->batchService->loadBatch($batchId);

        if (! $spec) {
            $this->error("Batch not found: {$batchId}");

            return self::FAILURE;
        }

        $results = [
            'valid' => 0,
            'errors' => 0,
            'warnings' => 0,
            'missing' => 0,
            'fixed' => 0,
        ];

        foreach ($spec['articles'] ?? [] as $article) {
            $slug = $article['slug'] ?? null;
            if (! $slug) {
                continue;
            }

            $draftPath = $this->findDraft($slug);

            if (! $draftPath) {
                $this->line("  <fg=gray>?</> <comment>{$slug}</comment> - No draft found");
                $results['missing']++;

                continue;
            }

            $validation = $this->batchService->validateDraft($draftPath);

            if ($fix && ! empty($validation['errors'])) {
                $fixedCount = $this->attemptFixes($draftPath, $validation);
                $results['fixed'] += $fixedCount;

                if ($fixedCount > 0) {
                    // Re-validate after fixes
                    $validation = $this->batchService->validateDraft($draftPath);
                }
            }

            $this->displayValidationResult($slug, $validation);

            if ($validation['valid'] && empty($validation['warnings'])) {
                $results['valid']++;
            } elseif ($validation['valid']) {
                $results['warnings']++;
            } else {
                $results['errors']++;
            }
        }

        $this->newLine();
        $this->displaySummary($results);

        if ($results['errors'] > 0) {
            return self::FAILURE;
        }

        if ($strict && $results['warnings'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function validateAllDrafts(bool $fix, bool $strict): int
    {
        $this->info('Validating all content drafts');
        $this->newLine();

        $draftsPath = base_path('doc/phase42/drafts');
        $files = $this->findAllDrafts($draftsPath);

        if (empty($files)) {
            $this->warn('No draft files found');

            return self::SUCCESS;
        }

        $this->line('Found <comment>'.count($files).'</comment> draft files');
        $this->newLine();

        $results = [
            'valid' => 0,
            'errors' => 0,
            'warnings' => 0,
            'fixed' => 0,
        ];

        foreach ($files as $file) {
            $slug = pathinfo($file, PATHINFO_FILENAME);
            $relativePath = str_replace(base_path().'/', '', $file);

            $validation = $this->batchService->validateDraft($file);

            if ($fix && ! empty($validation['errors'])) {
                $fixedCount = $this->attemptFixes($file, $validation);
                $results['fixed'] += $fixedCount;

                if ($fixedCount > 0) {
                    $validation = $this->batchService->validateDraft($file);
                }
            }

            $this->displayValidationResult($relativePath, $validation);

            if ($validation['valid'] && empty($validation['warnings'])) {
                $results['valid']++;
            } elseif ($validation['valid']) {
                $results['warnings']++;
            } else {
                $results['errors']++;
            }
        }

        $this->newLine();
        $this->displaySummary($results);

        if ($results['errors'] > 0) {
            return self::FAILURE;
        }

        if ($strict && $results['warnings'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function displayValidationResult(string $identifier, array $validation): void
    {
        if ($validation['valid'] && empty($validation['warnings'])) {
            $this->line("  <fg=green>✓</> <comment>{$identifier}</comment> - Valid ({$validation['word_count']} words)");

            return;
        }

        if ($validation['valid']) {
            $this->line("  <fg=yellow>!</> <comment>{$identifier}</comment> - Valid with warnings");
        } else {
            $this->line("  <fg=red>✗</> <comment>{$identifier}</comment> - Invalid");
        }

        foreach ($validation['errors'] as $error) {
            $this->line("      <fg=red>Error:</> {$error}");
        }

        foreach ($validation['warnings'] as $warning) {
            $this->line("      <fg=yellow>Warning:</> {$warning}");
        }
    }

    protected function displaySummary(array $results): void
    {
        $this->info('Validation Summary:');
        $this->table(
            ['Valid', 'Errors', 'Warnings', 'Missing', 'Fixed'],
            [[
                $results['valid'],
                $results['errors'],
                $results['warnings'],
                $results['missing'] ?? 0,
                $results['fixed'],
            ]]
        );
    }

    protected function attemptFixes(string $path, array $validation): int
    {
        $content = File::get($path);
        $fixed = 0;

        // Fix US to UK spellings
        $spellingFixes = [
            'color' => 'colour',
            'customize' => 'customise',
            'customization' => 'customisation',
            'organize' => 'organise',
            'organization' => 'organisation',
            'optimize' => 'optimise',
            'optimization' => 'optimisation',
            'analyze' => 'analyse',
            'analyzing' => 'analysing',
            'behavior' => 'behaviour',
            'favor' => 'favour',
            'favorite' => 'favourite',
            'center' => 'centre',
            'theater' => 'theatre',
            'catalog' => 'catalogue',
            'dialog' => 'dialogue',
            'fulfill' => 'fulfil',
            'license' => 'licence', // noun form
            'practice' => 'practise', // verb form - careful with this one
        ];

        foreach ($spellingFixes as $us => $uk) {
            $count = substr_count(strtolower($content), $us);
            if ($count > 0) {
                $content = preg_replace('/\b'.preg_quote($us, '/').'\b/i', $uk, $content);
                $fixed += $count;
            }
        }

        // Replace banned words with alternatives
        $bannedReplacements = [
            'leverage' => 'use',
            'leveraging' => 'using',
            'utilize' => 'use',
            'utilizing' => 'using',
            'utilization' => 'use',
            'synergy' => 'collaboration',
            'synergies' => 'efficiencies',
            'cutting-edge' => 'modern',
            'revolutionary' => 'new',
            'seamless' => 'smooth',
            'seamlessly' => 'smoothly',
            'robust' => 'reliable',
        ];

        foreach ($bannedReplacements as $banned => $replacement) {
            $count = substr_count(strtolower($content), $banned);
            if ($count > 0) {
                $content = preg_replace('/\b'.preg_quote($banned, '/').'\b/i', $replacement, $content);
                $fixed += $count;
            }
        }

        if ($fixed > 0) {
            File::put($path, $content);
        }

        return $fixed;
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

    protected function findAllDrafts(string $path): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'md') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
