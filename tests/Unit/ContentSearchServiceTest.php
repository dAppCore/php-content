<?php

declare(strict_types=1);

namespace Core\Content\Tests\Unit;

use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Core\Content\Enums\ContentType;
use Core\Content\Models\ContentItem;
use Core\Content\Models\ContentTaxonomy;
use Core\Content\Services\ContentSearchService;
use Tests\TestCase;

/**
 * Unit tests for ContentSearchService.
 */
class ContentSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ContentSearchService $searchService;

    protected Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->searchService = new ContentSearchService;

        // Create a test workspace
        $this->workspace = Workspace::factory()->create([
            'slug' => 'test-workspace',
        ]);
    }

    public function test_search_returns_empty_for_short_query(): void
    {
        $results = $this->searchService->search('a', [
            'workspace_id' => $this->workspace->id,
        ]);

        $this->assertEquals(0, $results->total());
    }

    public function test_search_finds_content_by_title(): void
    {
        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Getting Started with Laravel',
            'content_type' => ContentType::NATIVE,
            'status' => 'publish',
        ]);

        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Unrelated Article',
            'content_type' => ContentType::NATIVE,
            'status' => 'publish',
        ]);

        $results = $this->searchService->search('Laravel', [
            'workspace_id' => $this->workspace->id,
        ]);

        $this->assertEquals(1, $results->total());
        $this->assertStringContainsString('Laravel', $results->first()->title);
    }

    public function test_search_finds_content_by_body(): void
    {
        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'An Article',
            'content_html' => '<p>This article talks about authentication patterns.</p>',
            'content_type' => ContentType::NATIVE,
            'status' => 'publish',
        ]);

        $results = $this->searchService->search('authentication', [
            'workspace_id' => $this->workspace->id,
        ]);

        $this->assertEquals(1, $results->total());
    }

    public function test_search_finds_content_by_slug(): void
    {
        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'A Guide',
            'slug' => 'deployment-guide',
            'content_type' => ContentType::NATIVE,
            'status' => 'publish',
        ]);

        $results = $this->searchService->search('deployment', [
            'workspace_id' => $this->workspace->id,
        ]);

        $this->assertEquals(1, $results->total());
    }

    public function test_search_filters_by_type(): void
    {
        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Laravel Post',
            'type' => 'post',
            'content_type' => ContentType::NATIVE,
        ]);

        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Laravel Page',
            'type' => 'page',
            'content_type' => ContentType::NATIVE,
        ]);

        $results = $this->searchService->search('Laravel', [
            'workspace_id' => $this->workspace->id,
            'type' => 'post',
        ]);

        $this->assertEquals(1, $results->total());
        $this->assertEquals('post', $results->first()->type);
    }

    public function test_search_filters_by_status(): void
    {
        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Published Article',
            'status' => 'publish',
            'content_type' => ContentType::NATIVE,
        ]);

        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Draft Article',
            'status' => 'draft',
            'content_type' => ContentType::NATIVE,
        ]);

        $results = $this->searchService->search('Article', [
            'workspace_id' => $this->workspace->id,
            'status' => 'publish',
        ]);

        $this->assertEquals(1, $results->total());
        $this->assertEquals('publish', $results->first()->status);
    }

    public function test_search_filters_by_date_range(): void
    {
        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Recent Article',
            'content_type' => ContentType::NATIVE,
            'created_at' => now()->subDays(5),
        ]);

        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Old Article',
            'content_type' => ContentType::NATIVE,
            'created_at' => now()->subMonths(3),
        ]);

        $results = $this->searchService->search('Article', [
            'workspace_id' => $this->workspace->id,
            'date_from' => now()->subDays(10)->format('Y-m-d'),
        ]);

        $this->assertEquals(1, $results->total());
        $this->assertStringContainsString('Recent', $results->first()->title);
    }

    public function test_search_filters_by_category(): void
    {
        $category = ContentTaxonomy::factory()->create([
            'workspace_id' => $this->workspace->id,
            'type' => 'category',
            'slug' => 'tutorials',
            'name' => 'Tutorials',
        ]);

        $item = ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Laravel Tutorial',
            'content_type' => ContentType::NATIVE,
        ]);
        $item->taxonomies()->attach($category->id);

        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Laravel News',
            'content_type' => ContentType::NATIVE,
        ]);

        $results = $this->searchService->search('Laravel', [
            'workspace_id' => $this->workspace->id,
            'category' => 'tutorials',
        ]);

        $this->assertEquals(1, $results->total());
        $this->assertStringContainsString('Tutorial', $results->first()->title);
    }

    public function test_search_excludes_wordpress_content(): void
    {
        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Native Article',
            'content_type' => ContentType::NATIVE,
        ]);

        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'WordPress Article',
            'content_type' => ContentType::WORDPRESS,
        ]);

        $results = $this->searchService->search('Article', [
            'workspace_id' => $this->workspace->id,
        ]);

        // Should only find native content
        $this->assertEquals(1, $results->total());
        $this->assertEquals(ContentType::NATIVE, $results->first()->content_type);
    }

    public function test_search_respects_per_page_limit(): void
    {
        // Create 10 items
        for ($i = 1; $i <= 10; $i++) {
            ContentItem::factory()->create([
                'workspace_id' => $this->workspace->id,
                'title' => "Test Item {$i}",
                'content_type' => ContentType::NATIVE,
            ]);
        }

        $results = $this->searchService->search('Test', [
            'workspace_id' => $this->workspace->id,
            'per_page' => 5,
        ]);

        $this->assertEquals(10, $results->total());
        $this->assertCount(5, $results->items());
    }

    public function test_search_relevance_scoring(): void
    {
        // Exact title match should score highest
        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Laravel Guide',
            'content_type' => ContentType::NATIVE,
        ]);

        // Title starts with query
        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Laravel is Great',
            'content_type' => ContentType::NATIVE,
        ]);

        // Query in body only
        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'A Framework',
            'content_html' => '<p>This discusses Laravel concepts.</p>',
            'content_type' => ContentType::NATIVE,
        ]);

        $results = $this->searchService->search('Laravel', [
            'workspace_id' => $this->workspace->id,
        ]);

        $this->assertEquals(3, $results->total());

        // Results should be ordered by relevance
        $scores = $results->pluck('relevance_score')->all();
        $this->assertEquals($scores, collect($scores)->sortDesc()->values()->all());
    }

    public function test_suggest_returns_matching_titles(): void
    {
        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Getting Started',
            'content_type' => ContentType::NATIVE,
        ]);

        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Advanced Topics',
            'content_type' => ContentType::NATIVE,
        ]);

        $suggestions = $this->searchService->suggest('Getting', $this->workspace->id);

        $this->assertCount(1, $suggestions);
        $this->assertEquals('Getting Started', $suggestions->first()['title']);
    }

    public function test_suggest_limits_results(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            ContentItem::factory()->create([
                'workspace_id' => $this->workspace->id,
                'title' => "Test Item {$i}",
                'content_type' => ContentType::NATIVE,
            ]);
        }

        $suggestions = $this->searchService->suggest('Test', $this->workspace->id, 5);

        $this->assertCount(5, $suggestions);
    }

    public function test_get_backend_returns_database_by_default(): void
    {
        $this->assertEquals(ContentSearchService::BACKEND_DATABASE, $this->searchService->getBackend());
    }

    public function test_format_for_api_includes_all_fields(): void
    {
        ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Test Article',
            'content_type' => ContentType::NATIVE,
            'status' => 'publish',
        ]);

        $results = $this->searchService->search('Test', [
            'workspace_id' => $this->workspace->id,
        ]);

        $formatted = $this->searchService->formatForApi($results);

        $this->assertArrayHasKey('data', $formatted);
        $this->assertArrayHasKey('meta', $formatted);
        $this->assertArrayHasKey('backend', $formatted['meta']);
        $this->assertArrayHasKey('total', $formatted['meta']);

        $item = $formatted['data'][0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('title', $item);
        $this->assertArrayHasKey('slug', $item);
        $this->assertArrayHasKey('type', $item);
        $this->assertArrayHasKey('status', $item);
        $this->assertArrayHasKey('relevance_score', $item);
    }
}
