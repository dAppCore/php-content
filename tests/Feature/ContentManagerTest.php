<?php

use Core\Content\Models\ContentAuthor;
use Core\Content\Models\ContentItem;
use Core\Content\Models\ContentMedia;
use Core\Content\Models\ContentTaxonomy;
use Core\Content\Models\ContentWebhookLog;
use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    // Use existing seeded main workspace instead of creating duplicate
    $this->workspace = Workspace::where('slug', 'main')->first();
});

describe('ContentManager Routes', function () {
    it('requires authentication', function () {
        $response = $this->get('/hub/content-manager/main');
        $response->assertRedirect('/login');
    });

    // Note: Full view rendering tests are done via Playwright browser tests
    // The Flux Pro modal.header/modal.body components require browser context
    // These route existence tests verify the routes are correctly defined
    it('has route for default view', function () {
        expect(route('hub.content-manager', ['workspace' => 'main']))->toContain('/hub/content-manager/main');
    });

    it('has route for list view', function () {
        expect(route('hub.content-manager', ['workspace' => 'main', 'view' => 'list']))->toContain('/hub/content-manager/main/list');
    });

    it('has route for kanban view', function () {
        expect(route('hub.content-manager', ['workspace' => 'main', 'view' => 'kanban']))->toContain('/hub/content-manager/main/kanban');
    });

    it('has route for calendar view', function () {
        expect(route('hub.content-manager', ['workspace' => 'main', 'view' => 'calendar']))->toContain('/hub/content-manager/main/calendar');
    });

    it('has route for webhooks view', function () {
        expect(route('hub.content-manager', ['workspace' => 'main', 'view' => 'webhooks']))->toContain('/hub/content-manager/main/webhooks');
    });
});

describe('ContentItem Model', function () {
    it('belongs to a workspace', function () {
        $item = ContentItem::factory()->create(['workspace_id' => $this->workspace->id]);

        expect($item->workspace->id)->toBe($this->workspace->id);
    });

    it('belongs to an author', function () {
        $author = ContentAuthor::factory()->create(['workspace_id' => $this->workspace->id]);
        $item = ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'author_id' => $author->id,
        ]);

        expect($item->author->id)->toBe($author->id);
    });

    it('has many categories through pivot', function () {
        $item = ContentItem::factory()->create(['workspace_id' => $this->workspace->id]);
        $category = ContentTaxonomy::factory()->category()->create(['workspace_id' => $this->workspace->id]);

        $item->categories()->attach($category);

        expect($item->categories)->toHaveCount(1)
            ->and($item->categories->first()->id)->toBe($category->id);
    });

    it('has many tags through pivot', function () {
        $item = ContentItem::factory()->create(['workspace_id' => $this->workspace->id]);
        $tag = ContentTaxonomy::factory()->tag()->create(['workspace_id' => $this->workspace->id]);

        $item->tags()->attach($tag);

        expect($item->tags)->toHaveCount(1)
            ->and($item->tags->first()->id)->toBe($tag->id);
    });

    it('scopes to workspace', function () {
        $otherWorkspace = Workspace::factory()->create();

        ContentItem::factory()->count(3)->create(['workspace_id' => $this->workspace->id]);
        ContentItem::factory()->count(2)->create(['workspace_id' => $otherWorkspace->id]);

        $items = ContentItem::forWorkspace($this->workspace->id)->get();

        expect($items)->toHaveCount(3);
    });

    it('scopes to published', function () {
        ContentItem::factory()->published()->create(['workspace_id' => $this->workspace->id]);
        ContentItem::factory()->draft()->create(['workspace_id' => $this->workspace->id]);

        $items = ContentItem::forWorkspace($this->workspace->id)->published()->get();

        expect($items)->toHaveCount(1)
            ->and($items->first()->status)->toBe('publish');
    });

    it('scopes to posts', function () {
        ContentItem::factory()->post()->create(['workspace_id' => $this->workspace->id]);
        ContentItem::factory()->page()->create(['workspace_id' => $this->workspace->id]);

        $items = ContentItem::forWorkspace($this->workspace->id)->posts()->get();

        expect($items)->toHaveCount(1)
            ->and($items->first()->type)->toBe('post');
    });

    it('scopes to pages', function () {
        ContentItem::factory()->post()->create(['workspace_id' => $this->workspace->id]);
        ContentItem::factory()->page()->create(['workspace_id' => $this->workspace->id]);

        $items = ContentItem::forWorkspace($this->workspace->id)->pages()->get();

        expect($items)->toHaveCount(1)
            ->and($items->first()->type)->toBe('page');
    });

    it('scopes to items needing sync', function () {
        ContentItem::factory()->synced()->create(['workspace_id' => $this->workspace->id]);
        ContentItem::factory()->pendingSync()->create(['workspace_id' => $this->workspace->id]);
        ContentItem::factory()->failed()->create(['workspace_id' => $this->workspace->id]);

        $items = ContentItem::forWorkspace($this->workspace->id)->needsSync()->get();

        expect($items)->toHaveCount(2);
    });

    it('marks as synced', function () {
        $item = ContentItem::factory()->pendingSync()->create(['workspace_id' => $this->workspace->id]);

        $item->markSynced();

        expect($item->fresh()->sync_status)->toBe('synced')
            ->and($item->fresh()->synced_at)->not->toBeNull();
    });

    it('marks as failed', function () {
        $item = ContentItem::factory()->create(['workspace_id' => $this->workspace->id]);

        $item->markFailed('Connection timeout');

        expect($item->fresh()->sync_status)->toBe('failed')
            ->and($item->fresh()->sync_error)->toBe('Connection timeout');
    });

    it('generates cdn urls for purge', function () {
        $item = ContentItem::factory()->post()->create([
            'workspace_id' => $this->workspace->id,
            'slug' => 'test-post',
        ]);

        $urls = $item->cdn_urls_for_purge;

        expect($urls)->toContain("https://{$this->workspace->domain}/blog/test-post")
            ->and($urls)->toContain("https://{$this->workspace->domain}/");
    });
});

describe('ContentAuthor Model', function () {
    it('belongs to a workspace', function () {
        $author = ContentAuthor::factory()->create(['workspace_id' => $this->workspace->id]);

        expect($author->workspace->id)->toBe($this->workspace->id);
    });

    it('has many content items', function () {
        $author = ContentAuthor::factory()->create(['workspace_id' => $this->workspace->id]);
        ContentItem::factory()->count(3)->create([
            'workspace_id' => $this->workspace->id,
            'author_id' => $author->id,
        ]);

        expect($author->contentItems)->toHaveCount(3);
    });

    it('scopes by WordPress ID', function () {
        ContentAuthor::factory()->create(['workspace_id' => $this->workspace->id, 'wp_id' => 123]);
        ContentAuthor::factory()->create(['workspace_id' => $this->workspace->id, 'wp_id' => 456]);

        $author = ContentAuthor::forWorkspace($this->workspace->id)->byWpId(123)->first();

        expect($author->wp_id)->toBe(123);
    });
});

describe('ContentTaxonomy Model', function () {
    it('belongs to a workspace', function () {
        $taxonomy = ContentTaxonomy::factory()->create(['workspace_id' => $this->workspace->id]);

        expect($taxonomy->workspace->id)->toBe($this->workspace->id);
    });

    it('scopes to categories', function () {
        ContentTaxonomy::factory()->category()->create(['workspace_id' => $this->workspace->id]);
        ContentTaxonomy::factory()->tag()->create(['workspace_id' => $this->workspace->id]);

        $categories = ContentTaxonomy::forWorkspace($this->workspace->id)->categories()->get();

        expect($categories)->toHaveCount(1)
            ->and($categories->first()->type)->toBe('category');
    });

    it('scopes to tags', function () {
        ContentTaxonomy::factory()->category()->create(['workspace_id' => $this->workspace->id]);
        ContentTaxonomy::factory()->tag()->create(['workspace_id' => $this->workspace->id]);

        $tags = ContentTaxonomy::forWorkspace($this->workspace->id)->tags()->get();

        expect($tags)->toHaveCount(1)
            ->and($tags->first()->type)->toBe('tag');
    });

    it('has many content items through pivot', function () {
        $category = ContentTaxonomy::factory()->category()->create(['workspace_id' => $this->workspace->id]);
        $item = ContentItem::factory()->create(['workspace_id' => $this->workspace->id]);

        $item->categories()->attach($category);

        expect($category->contentItems)->toHaveCount(1);
    });
});

describe('ContentMedia Model', function () {
    it('belongs to a workspace', function () {
        $media = ContentMedia::factory()->create(['workspace_id' => $this->workspace->id]);

        expect($media->workspace->id)->toBe($this->workspace->id);
    });

    it('returns cdn url when available', function () {
        $media = ContentMedia::factory()->withCdn()->create(['workspace_id' => $this->workspace->id]);

        expect($media->url)->toBe($media->cdn_url);
    });

    it('returns source url when no cdn', function () {
        $media = ContentMedia::factory()->withoutCdn()->create(['workspace_id' => $this->workspace->id]);

        expect($media->url)->toBe($media->source_url);
    });

    it('detects if is image', function () {
        $image = ContentMedia::factory()->jpeg()->create(['workspace_id' => $this->workspace->id]);
        $pdf = ContentMedia::factory()->pdf()->create(['workspace_id' => $this->workspace->id]);

        expect($image->is_image)->toBeTrue()
            ->and($pdf->is_image)->toBeFalse();
    });

    it('scopes to images only', function () {
        ContentMedia::factory()->jpeg()->create(['workspace_id' => $this->workspace->id]);
        ContentMedia::factory()->pdf()->create(['workspace_id' => $this->workspace->id]);

        $images = ContentMedia::forWorkspace($this->workspace->id)->images()->get();

        expect($images)->toHaveCount(1);
    });
});

describe('ContentWebhookLog Model', function () {
    it('belongs to a workspace', function () {
        $log = ContentWebhookLog::factory()->create(['workspace_id' => $this->workspace->id]);

        expect($log->workspace->id)->toBe($this->workspace->id);
    });

    it('marks as processing', function () {
        $log = ContentWebhookLog::factory()->pending()->create(['workspace_id' => $this->workspace->id]);

        $log->markProcessing();

        expect($log->fresh()->status)->toBe('processing');
    });

    it('marks as completed', function () {
        $log = ContentWebhookLog::factory()->processing()->create(['workspace_id' => $this->workspace->id]);

        $log->markCompleted();

        expect($log->fresh()->status)->toBe('completed')
            ->and($log->fresh()->processed_at)->not->toBeNull()
            ->and($log->fresh()->error_message)->toBeNull();
    });

    it('marks as failed', function () {
        $log = ContentWebhookLog::factory()->processing()->create(['workspace_id' => $this->workspace->id]);

        $log->markFailed('API error');

        expect($log->fresh()->status)->toBe('failed')
            ->and($log->fresh()->error_message)->toBe('API error');
    });

    it('scopes to pending', function () {
        ContentWebhookLog::factory()->pending()->create(['workspace_id' => $this->workspace->id]);
        ContentWebhookLog::factory()->completed()->create(['workspace_id' => $this->workspace->id]);

        $pending = ContentWebhookLog::forWorkspace($this->workspace->id)->pending()->get();

        expect($pending)->toHaveCount(1)
            ->and($pending->first()->status)->toBe('pending');
    });

    it('scopes to failed', function () {
        ContentWebhookLog::factory()->pending()->create(['workspace_id' => $this->workspace->id]);
        ContentWebhookLog::factory()->failed()->create(['workspace_id' => $this->workspace->id]);

        $failed = ContentWebhookLog::forWorkspace($this->workspace->id)->failed()->get();

        expect($failed)->toHaveCount(1)
            ->and($failed->first()->status)->toBe('failed');
    });
});

describe('Content Queries', function () {
    // Note: Visual display tests are done via Playwright browser tests
    // These tests verify the data querying and relationships work correctly

    it('can query content items with filters', function () {
        ContentItem::factory()->published()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Test Blog Post Title',
        ]);
        ContentItem::factory()->draft()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Draft Post',
        ]);

        $published = ContentItem::forWorkspace($this->workspace->id)->published()->get();
        $all = ContentItem::forWorkspace($this->workspace->id)->get();

        expect($published)->toHaveCount(1)
            ->and($published->first()->title)->toBe('Test Blog Post Title')
            ->and($all)->toHaveCount(2);
    });

    it('can query stats for workspace', function () {
        ContentItem::factory()->count(5)->published()->create(['workspace_id' => $this->workspace->id]);
        ContentItem::factory()->count(2)->draft()->create(['workspace_id' => $this->workspace->id]);
        ContentWebhookLog::factory()->count(3)->completed()->create(['workspace_id' => $this->workspace->id]);
        ContentWebhookLog::factory()->failed()->create(['workspace_id' => $this->workspace->id]);

        $publishedCount = ContentItem::forWorkspace($this->workspace->id)->published()->count();
        $draftCount = ContentItem::forWorkspace($this->workspace->id)->where('status', 'draft')->count();
        $webhooksTotal = ContentWebhookLog::forWorkspace($this->workspace->id)->count();
        $webhooksFailed = ContentWebhookLog::forWorkspace($this->workspace->id)->failed()->count();

        expect($publishedCount)->toBe(5)
            ->and($draftCount)->toBe(2)
            ->and($webhooksTotal)->toBe(4)
            ->and($webhooksFailed)->toBe(1);
    });

    it('can query webhook logs by status', function () {
        ContentWebhookLog::factory()->pending()->create([
            'workspace_id' => $this->workspace->id,
            'event_type' => 'post.created',
        ]);
        ContentWebhookLog::factory()->completed()->create([
            'workspace_id' => $this->workspace->id,
            'event_type' => 'post.updated',
        ]);
        ContentWebhookLog::factory()->failed()->create([
            'workspace_id' => $this->workspace->id,
            'event_type' => 'post.deleted',
        ]);

        $pending = ContentWebhookLog::forWorkspace($this->workspace->id)->pending()->get();
        $failed = ContentWebhookLog::forWorkspace($this->workspace->id)->failed()->get();

        expect($pending)->toHaveCount(1)
            ->and($pending->first()->event_type)->toBe('post.created')
            ->and($failed)->toHaveCount(1)
            ->and($failed->first()->event_type)->toBe('post.deleted');
    });
});
