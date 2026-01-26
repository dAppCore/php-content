<?php

declare(strict_types=1);

namespace Core\Content\Console\Commands;

use Core\Content\Enums\ContentType;
use Core\Content\Models\ContentAuthor;
use Core\Content\Models\ContentItem;
use Core\Content\Models\ContentMedia;
use Core\Content\Models\ContentTaxonomy;
use Core\Mod\Tenant\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Import content from a WordPress site via REST API.
 *
 * This command imports posts, pages, categories, tags, authors, and media
 * from a WordPress site into the native content system. It preserves
 * WordPress IDs in the wp_id field for future reference and is idempotent
 * (re-running updates existing records, doesn't duplicate).
 */
class ContentImportWordPress extends Command
{
    protected $signature = 'content:import-wordpress
                            {url : WordPress site URL (e.g., https://example.com)}
                            {--workspace= : Target workspace ID or slug (defaults to main)}
                            {--types=posts,pages : Content types to import (posts,pages,media,authors,categories,tags)}
                            {--since= : Only import content modified after this date (ISO 8601 format)}
                            {--limit= : Maximum number of items to import per type}
                            {--skip-media : Skip downloading media files}
                            {--dry-run : Preview what would be imported without making changes}
                            {--username= : WordPress username for authenticated endpoints}
                            {--password= : WordPress application password}';

    protected $description = 'Import content from a WordPress site via REST API';

    protected string $baseUrl;

    protected ?string $token = null;

    protected ?Workspace $workspace = null;

    protected bool $dryRun = false;

    protected bool $skipMedia = false;

    protected ?Carbon $since = null;

    protected ?int $limit = null;

    protected array $stats = [
        'authors' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
        'categories' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
        'tags' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
        'media' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'downloaded' => 0],
        'posts' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
        'pages' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
    ];

    protected array $authorMap = [];   // wp_id => local_id

    protected array $categoryMap = []; // wp_id => local_id

    protected array $tagMap = [];      // wp_id => local_id

    protected array $mediaMap = [];    // wp_id => local_id

    public function handle(): int
    {
        $this->baseUrl = rtrim($this->argument('url'), '/');
        $this->dryRun = $this->option('dry-run');
        $this->skipMedia = $this->option('skip-media');
        $this->limit = $this->option('limit') ? (int) $this->option('limit') : null;

        if ($this->option('since')) {
            try {
                $this->since = Carbon::parse($this->option('since'));
            } catch (\Exception $e) {
                $this->error("Invalid date format for --since: {$this->option('since')}");

                return self::FAILURE;
            }
        }

        // Validate WordPress site is accessible
        if (! $this->validateWordPressSite()) {
            return self::FAILURE;
        }

        // Authenticate if credentials provided
        if ($this->option('username') && $this->option('password')) {
            if (! $this->authenticate()) {
                $this->error('Failed to authenticate with WordPress. Check credentials.');

                return self::FAILURE;
            }
            $this->info('Authenticated successfully.');
        }

        // Resolve workspace
        if (! $this->resolveWorkspace()) {
            return self::FAILURE;
        }

        $this->info('');
        $this->info("Importing from: {$this->baseUrl}");
        $this->info("Target workspace: {$this->workspace->name} (ID: {$this->workspace->id})");
        if ($this->since) {
            $this->info("Modified since: {$this->since->toDateTimeString()}");
        }
        if ($this->dryRun) {
            $this->warn('DRY RUN - No changes will be made');
        }
        $this->info('');

        $types = explode(',', $this->option('types'));

        // Import in dependency order
        if (in_array('authors', $types)) {
            $this->importAuthors();
        }

        if (in_array('categories', $types)) {
            $this->importCategories();
        }

        if (in_array('tags', $types)) {
            $this->importTags();
        }

        if (in_array('media', $types)) {
            $this->importMedia();
        }

        if (in_array('posts', $types)) {
            $this->importPosts();
        }

        if (in_array('pages', $types)) {
            $this->importPages();
        }

        $this->displaySummary();

        return self::SUCCESS;
    }

    /**
     * Validate the WordPress site is accessible and has REST API.
     */
    protected function validateWordPressSite(): bool
    {
        $this->info('Validating WordPress site...');

        try {
            $response = Http::timeout(10)->get("{$this->baseUrl}/wp-json");

            if ($response->failed()) {
                $this->error("Cannot access WordPress REST API at {$this->baseUrl}/wp-json");
                $this->error("Status: {$response->status()}");

                return false;
            }

            $info = $response->json();
            $siteName = $info['name'] ?? 'Unknown';
            $this->info("Connected to: {$siteName}");

            return true;
        } catch (\Exception $e) {
            $this->error("Failed to connect to WordPress site: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Authenticate with WordPress using JWT or application passwords.
     */
    protected function authenticate(): bool
    {
        // Try JWT auth first (if plugin installed)
        $response = Http::timeout(10)->post("{$this->baseUrl}/wp-json/jwt-auth/v1/token", [
            'username' => $this->option('username'),
            'password' => $this->option('password'),
        ]);

        if ($response->successful()) {
            $this->token = $response->json('token');

            return true;
        }

        // Fall back to Basic Auth with application password
        $this->token = base64_encode($this->option('username').':'.$this->option('password'));

        return true;
    }

    /**
     * Get HTTP client with authentication.
     */
    protected function client()
    {
        $http = Http::timeout(30)
            ->acceptJson()
            ->baseUrl("{$this->baseUrl}/wp-json/wp/v2");

        if ($this->token) {
            // Check if it's a JWT token or basic auth
            if (str_starts_with($this->token, 'eyJ')) {
                $http = $http->withToken($this->token);
            } else {
                $http = $http->withHeaders(['Authorization' => "Basic {$this->token}"]);
            }
        }

        return $http;
    }

    /**
     * Resolve target workspace.
     */
    protected function resolveWorkspace(): bool
    {
        $workspaceInput = $this->option('workspace') ?? 'main';

        $this->workspace = is_numeric($workspaceInput)
            ? Workspace::find($workspaceInput)
            : Workspace::where('slug', $workspaceInput)->first();

        if (! $this->workspace) {
            $this->error("Workspace not found: {$workspaceInput}");

            return false;
        }

        return true;
    }

    /**
     * Import authors from WordPress users.
     */
    protected function importAuthors(): void
    {
        $this->info('Importing authors...');

        $page = 1;
        $imported = 0;
        $progressStarted = false;

        do {
            $response = $this->client()->get('/users', [
                'page' => $page,
                'per_page' => 100,
            ]);

            if ($response->failed()) {
                $this->warn("Failed to fetch authors page {$page}");
                break;
            }

            $users = $response->json();
            $total = (int) $response->header('X-WP-Total', count($users));

            if (empty($users)) {
                break;
            }

            if (! $progressStarted) {
                $this->output->progressStart($total);
                $progressStarted = true;
            }

            foreach ($users as $user) {
                $result = $this->importAuthor($user);
                $this->stats['authors'][$result]++;
                $imported++;
                $this->output->progressAdvance();

                if ($this->limit && $imported >= $this->limit) {
                    break 2;
                }
            }

            $page++;
            $hasMore = count($users) === 100;
        } while ($hasMore);

        if ($progressStarted) {
            $this->output->progressFinish();
        }
        $this->newLine();
    }

    /**
     * Import a single author.
     */
    protected function importAuthor(array $user): string
    {
        $wpId = $user['id'];

        // Check if already exists
        $existing = ContentAuthor::forWorkspace($this->workspace->id)
            ->byWpId($wpId)
            ->first();

        $data = [
            'workspace_id' => $this->workspace->id,
            'wp_id' => $wpId,
            'name' => $user['name'] ?? '',
            'slug' => $user['slug'] ?? Str::slug($user['name'] ?? 'author-'.$wpId),
            'avatar_url' => $user['avatar_urls']['96'] ?? null,
            'bio' => $user['description'] ?? null,
        ];

        if ($this->dryRun) {
            if ($existing) {
                $this->authorMap[$wpId] = $existing->id;

                return 'skipped';
            }

            return 'created';
        }

        if ($existing) {
            $existing->update($data);
            $this->authorMap[$wpId] = $existing->id;

            return 'updated';
        }

        $author = ContentAuthor::create($data);
        $this->authorMap[$wpId] = $author->id;

        return 'created';
    }

    /**
     * Import categories.
     */
    protected function importCategories(): void
    {
        $this->info('Importing categories...');

        $page = 1;
        $imported = 0;
        $progressStarted = false;

        do {
            $response = $this->client()->get('/categories', [
                'page' => $page,
                'per_page' => 100,
            ]);

            if ($response->failed()) {
                $this->warn("Failed to fetch categories page {$page}");
                break;
            }

            $categories = $response->json();
            $total = (int) $response->header('X-WP-Total', count($categories));

            if (empty($categories)) {
                break;
            }

            if (! $progressStarted) {
                $this->output->progressStart($total);
                $progressStarted = true;
            }

            foreach ($categories as $category) {
                $result = $this->importTaxonomy($category, 'category');
                $this->stats['categories'][$result]++;
                $imported++;
                $this->output->progressAdvance();

                if ($this->limit && $imported >= $this->limit) {
                    break 2;
                }
            }

            $page++;
            $hasMore = count($categories) === 100;
        } while ($hasMore);

        if ($progressStarted) {
            $this->output->progressFinish();
        }
        $this->newLine();
    }

    /**
     * Import tags.
     */
    protected function importTags(): void
    {
        $this->info('Importing tags...');

        $page = 1;
        $imported = 0;
        $progressStarted = false;

        do {
            $response = $this->client()->get('/tags', [
                'page' => $page,
                'per_page' => 100,
            ]);

            if ($response->failed()) {
                $this->warn("Failed to fetch tags page {$page}");
                break;
            }

            $tags = $response->json();
            $total = (int) $response->header('X-WP-Total', count($tags));

            if (empty($tags)) {
                break;
            }

            if (! $progressStarted) {
                $this->output->progressStart($total);
                $progressStarted = true;
            }

            foreach ($tags as $tag) {
                $result = $this->importTaxonomy($tag, 'tag');
                $this->stats['tags'][$result]++;
                $imported++;
                $this->output->progressAdvance();

                if ($this->limit && $imported >= $this->limit) {
                    break 2;
                }
            }

            $page++;
            $hasMore = count($tags) === 100;
        } while ($hasMore);

        if ($progressStarted) {
            $this->output->progressFinish();
        }
        $this->newLine();
    }

    /**
     * Import a single taxonomy (category or tag).
     */
    protected function importTaxonomy(array $term, string $type): string
    {
        $wpId = $term['id'];
        $map = $type === 'category' ? 'categoryMap' : 'tagMap';

        // Check if already exists
        $existing = ContentTaxonomy::forWorkspace($this->workspace->id)
            ->where('type', $type)
            ->byWpId($wpId)
            ->first();

        $data = [
            'workspace_id' => $this->workspace->id,
            'wp_id' => $wpId,
            'type' => $type,
            'name' => $this->decodeText($term['name'] ?? ''),
            'slug' => $term['slug'] ?? Str::slug($term['name'] ?? $type.'-'.$wpId),
            'description' => $term['description'] ?? null,
            'parent_wp_id' => $term['parent'] ?? null,
            'count' => $term['count'] ?? 0,
        ];

        if ($this->dryRun) {
            if ($existing) {
                $this->$map[$wpId] = $existing->id;

                return 'skipped';
            }

            return 'created';
        }

        if ($existing) {
            $existing->update($data);
            $this->$map[$wpId] = $existing->id;

            return 'updated';
        }

        $taxonomy = ContentTaxonomy::create($data);
        $this->$map[$wpId] = $taxonomy->id;

        return 'created';
    }

    /**
     * Import media files.
     */
    protected function importMedia(): void
    {
        $this->info('Importing media...');

        $page = 1;
        $imported = 0;
        $progressStarted = false;
        $params = [
            'page' => $page,
            'per_page' => 20, // Smaller batches for media
        ];

        if ($this->since) {
            $params['modified_after'] = $this->since->toIso8601String();
        }

        do {
            $params['page'] = $page;
            $response = $this->client()->get('/media', $params);

            if ($response->failed()) {
                $this->warn("Failed to fetch media page {$page}");
                break;
            }

            $media = $response->json();
            $total = (int) $response->header('X-WP-Total', count($media));

            if (empty($media)) {
                break;
            }

            if (! $progressStarted) {
                $this->output->progressStart($total);
                $progressStarted = true;
            }

            foreach ($media as $item) {
                $result = $this->importMediaItem($item);
                $this->stats['media'][$result]++;
                $imported++;
                $this->output->progressAdvance();

                if ($this->limit && $imported >= $this->limit) {
                    break 2;
                }
            }

            $page++;
            $hasMore = count($media) === 20;
        } while ($hasMore);

        if ($progressStarted) {
            $this->output->progressFinish();
        }
        $this->newLine();
    }

    /**
     * Import a single media item.
     */
    protected function importMediaItem(array $item): string
    {
        $wpId = $item['id'];

        // Check if already exists
        $existing = ContentMedia::forWorkspace($this->workspace->id)
            ->byWpId($wpId)
            ->first();

        $sourceUrl = $item['source_url'] ?? ($item['guid']['rendered'] ?? null);
        $mimeType = $item['mime_type'] ?? 'application/octet-stream';
        $filename = basename(parse_url($sourceUrl, PHP_URL_PATH) ?? "media-{$wpId}");

        // Parse sizes from media_details
        $sizes = [];
        if (isset($item['media_details']['sizes'])) {
            foreach ($item['media_details']['sizes'] as $sizeName => $sizeData) {
                $sizes[$sizeName] = [
                    'source_url' => $sizeData['source_url'] ?? null,
                    'width' => $sizeData['width'] ?? null,
                    'height' => $sizeData['height'] ?? null,
                ];
            }
        }

        $data = [
            'workspace_id' => $this->workspace->id,
            'wp_id' => $wpId,
            'title' => $this->decodeText($item['title']['rendered'] ?? $filename),
            'filename' => $filename,
            'mime_type' => $mimeType,
            'file_size' => $item['media_details']['filesize'] ?? 0,
            'source_url' => $sourceUrl,
            'width' => $item['media_details']['width'] ?? null,
            'height' => $item['media_details']['height'] ?? null,
            'alt_text' => $item['alt_text'] ?? null,
            'caption' => $item['caption']['rendered'] ?? null,
            'sizes' => $sizes,
        ];

        if ($this->dryRun) {
            if ($existing) {
                $this->mediaMap[$wpId] = $existing->id;

                return 'skipped';
            }

            return 'created';
        }

        // Download media file if not existing and not skipping
        $localPath = null;
        if ($sourceUrl && ! $this->skipMedia) {
            $localPath = $this->downloadMedia($sourceUrl, $filename);
            if ($localPath) {
                $data['cdn_url'] = Storage::disk('content-media')->url($localPath);
                $this->stats['media']['downloaded']++;
            }
        }

        if ($existing) {
            $existing->update($data);
            $this->mediaMap[$wpId] = $existing->id;

            return 'updated';
        }

        $media = ContentMedia::create($data);
        $this->mediaMap[$wpId] = $media->id;

        return 'created';
    }

    /**
     * Download a media file.
     */
    protected function downloadMedia(string $url, string $filename): ?string
    {
        try {
            $response = Http::timeout(60)->get($url);

            if ($response->failed()) {
                $this->warn("Failed to download: {$url}");

                return null;
            }

            $path = "imports/{$this->workspace->slug}/".date('Y/m')."/{$filename}";

            Storage::disk('content-media')->put($path, $response->body());

            return $path;
        } catch (\Exception $e) {
            $this->warn("Error downloading {$url}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Import posts.
     */
    protected function importPosts(): void
    {
        $this->info('Importing posts...');
        $this->importContentType('posts');
    }

    /**
     * Import pages.
     */
    protected function importPages(): void
    {
        $this->info('Importing pages...');
        $this->importContentType('pages');
    }

    /**
     * Import content of a specific type (posts or pages).
     */
    protected function importContentType(string $type): void
    {
        $page = 1;
        $imported = 0;
        $progressStarted = false;
        $params = [
            'page' => $page,
            'per_page' => 50,
            'status' => 'any',
            '_embed' => true,
        ];

        if ($this->since) {
            $params['modified_after'] = $this->since->toIso8601String();
        }

        do {
            $params['page'] = $page;
            $response = $this->client()->get("/{$type}", $params);

            if ($response->failed()) {
                $this->warn("Failed to fetch {$type} page {$page}");
                break;
            }

            $items = $response->json();
            $total = (int) $response->header('X-WP-Total', count($items));

            if (empty($items)) {
                break;
            }

            if (! $progressStarted) {
                $this->output->progressStart(min($total, $this->limit ?? $total));
                $progressStarted = true;
            }

            foreach ($items as $item) {
                $result = $this->importContentItem($item, $type === 'posts' ? 'post' : 'page');
                $this->stats[$type][$result]++;
                $imported++;
                $this->output->progressAdvance();

                if ($this->limit && $imported >= $this->limit) {
                    break 2;
                }
            }

            $page++;
            $hasMore = count($items) === 50;
        } while ($hasMore);

        if ($progressStarted) {
            $this->output->progressFinish();
        }
        $this->newLine();
    }

    /**
     * Import a single content item (post or page).
     */
    protected function importContentItem(array $item, string $type): string
    {
        $wpId = $item['id'];

        // Check modification date for --since filter
        if ($this->since) {
            $modified = Carbon::parse($item['modified_gmt'] ?? $item['modified']);
            if ($modified->lt($this->since)) {
                return 'skipped';
            }
        }

        // Check if already exists
        $existing = ContentItem::forWorkspace($this->workspace->id)
            ->where('wp_id', $wpId)
            ->first();

        // Map WordPress status to our status
        $status = match ($item['status']) {
            'publish' => 'publish',
            'draft' => 'draft',
            'pending' => 'pending',
            'future' => 'future',
            'private' => 'private',
            default => 'draft',
        };

        // Get author ID from map
        $authorId = null;
        if (isset($item['author'])) {
            $authorId = $this->authorMap[$item['author']] ?? null;
        }

        // Get featured media ID from map
        $featuredMediaId = null;
        if (isset($item['featured_media']) && $item['featured_media'] > 0) {
            $featuredMediaId = $item['featured_media'];
        }

        $data = [
            'workspace_id' => $this->workspace->id,
            'content_type' => ContentType::WORDPRESS->value, // Mark as WordPress import
            'wp_id' => $wpId,
            'wp_guid' => $item['guid']['rendered'] ?? null,
            'type' => $type,
            'status' => $status,
            'slug' => $item['slug'] ?? Str::slug($item['title']['rendered'] ?? 'untitled-'.$wpId),
            'title' => $this->decodeText($item['title']['rendered'] ?? ''),
            'excerpt' => strip_tags($item['excerpt']['rendered'] ?? ''),
            'content_html_original' => $item['content']['rendered'] ?? '',
            'content_html_clean' => $this->cleanHtml($item['content']['rendered'] ?? ''),
            'content_html' => $item['content']['rendered'] ?? '',
            'author_id' => $authorId,
            'featured_media_id' => $featuredMediaId,
            'wp_created_at' => Carbon::parse($item['date_gmt'] ?? $item['date']),
            'wp_modified_at' => Carbon::parse($item['modified_gmt'] ?? $item['modified']),
            'sync_status' => 'synced',
            'synced_at' => now(),
        ];

        // Handle scheduled posts
        if ($status === 'future' && isset($item['date_gmt'])) {
            $data['publish_at'] = Carbon::parse($item['date_gmt']);
        }

        // Extract SEO from Yoast or other plugins
        $seoMeta = $this->extractSeoMeta($item);
        if (! empty($seoMeta)) {
            $data['seo_meta'] = $seoMeta;
        }

        if ($this->dryRun) {
            return $existing ? 'skipped' : 'created';
        }

        if ($existing) {
            $existing->update($data);
            $contentItem = $existing;
        } else {
            $contentItem = ContentItem::create($data);
        }

        // Sync categories
        if ($type === 'post' && isset($item['categories'])) {
            $categoryIds = collect($item['categories'])
                ->map(fn ($wpId) => $this->categoryMap[$wpId] ?? null)
                ->filter()
                ->values()
                ->all();

            if (! empty($categoryIds)) {
                $contentItem->taxonomies()->syncWithoutDetaching($categoryIds);
            }
        }

        // Sync tags
        if ($type === 'post' && isset($item['tags'])) {
            $tagIds = collect($item['tags'])
                ->map(fn ($wpId) => $this->tagMap[$wpId] ?? null)
                ->filter()
                ->values()
                ->all();

            if (! empty($tagIds)) {
                $contentItem->taxonomies()->syncWithoutDetaching($tagIds);
            }
        }

        return $existing ? 'updated' : 'created';
    }

    /**
     * Clean HTML content (remove WordPress-specific markup).
     */
    protected function cleanHtml(string $html): string
    {
        // Remove WordPress block comments
        $html = preg_replace('/<!--\s*\/?wp:[^>]*-->/s', '', $html);

        // Remove empty paragraphs
        $html = preg_replace('/<p>\s*<\/p>/i', '', $html);

        // Clean up multiple newlines
        $html = preg_replace('/\n{3,}/', "\n\n", $html);

        return trim($html);
    }

    /**
     * Decode HTML entities and normalize smart quotes to ASCII.
     */
    protected function decodeText(string $text): string
    {
        // Decode HTML entities (including numeric like &#8217;)
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize smart quotes and other typographic characters to ASCII
        $search = [
            "\u{2019}", // RIGHT SINGLE QUOTATION MARK
            "\u{2018}", // LEFT SINGLE QUOTATION MARK
            "\u{201C}", // LEFT DOUBLE QUOTATION MARK
            "\u{201D}", // RIGHT DOUBLE QUOTATION MARK
            "\u{2013}", // EN DASH
            "\u{2014}", // EM DASH
            "\u{2026}", // HORIZONTAL ELLIPSIS
        ];
        $replace = ["'", "'", '"', '"', '-', '-', '...'];

        return str_replace($search, $replace, $decoded);
    }

    /**
     * Extract SEO metadata from WordPress item.
     */
    protected function extractSeoMeta(array $item): array
    {
        $seo = [];

        // Check for Yoast SEO data in _yoast_wpseo meta
        if (isset($item['yoast_head_json'])) {
            $yoast = $item['yoast_head_json'];
            $seo['title'] = $yoast['title'] ?? null;
            $seo['description'] = $yoast['description'] ?? null;
            $seo['og_image'] = $yoast['og_image'][0]['url'] ?? null;
            $seo['canonical'] = $yoast['canonical'] ?? null;
            $seo['robots'] = $yoast['robots'] ?? null;
        }

        // Check for RankMath
        if (isset($item['rank_math_seo'])) {
            $rm = $item['rank_math_seo'];
            $seo['title'] = $rm['title'] ?? $seo['title'] ?? null;
            $seo['description'] = $rm['description'] ?? $seo['description'] ?? null;
        }

        // Filter out null values
        return array_filter($seo);
    }

    /**
     * Display import summary.
     */
    protected function displaySummary(): void
    {
        $this->newLine();
        $this->info('Import Summary');
        $this->info('==============');

        $rows = [];
        foreach ($this->stats as $type => $counts) {
            $rows[] = [
                ucfirst($type),
                $counts['created'],
                $counts['updated'],
                $counts['skipped'],
                ($counts['downloaded'] ?? 0) ?: '-',
            ];
        }

        $this->table(
            ['Type', 'Created', 'Updated', 'Skipped', 'Downloaded'],
            $rows
        );

        if ($this->dryRun) {
            $this->newLine();
            $this->warn('This was a dry run. No changes were made.');
        }
    }
}
