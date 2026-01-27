<?php

use Core\Mod\Content\Models\ContentAuthor;
use Core\Mod\Content\Models\ContentItem;
use Core\Mod\Content\Models\ContentMedia;
use Core\Mod\Content\Models\ContentTaxonomy;
use Core\Mod\Content\Models\ContentWebhookLog;
use Core\Mod\Tenant\Models\Workspace;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Factory Tests', function () {
    it('creates a workspace', function () {
        $workspace = Workspace::factory()->create();

        expect($workspace)->toBeInstanceOf(Workspace::class)
            ->and($workspace->name)->toBeString()
            ->and($workspace->slug)->toBeString()
            ->and($workspace->domain)->toContain('.host.uk.com')
            ->and($workspace->is_active)->toBeTrue();
    });

    it('creates a main workspace', function () {
        // Use existing seeded main workspace instead of creating duplicate
        $workspace = Workspace::where('slug', 'main')->first();

        expect($workspace)->not->toBeNull()
            ->and($workspace->slug)->toBe('main');
    });

    it('creates a content author', function () {
        $author = ContentAuthor::factory()->create();

        expect($author)->toBeInstanceOf(ContentAuthor::class)
            ->and($author->name)->toBeString()
            ->and($author->email)->toContain('@')
            ->and($author->workspace_id)->toBeInt();
    });

    it('creates a content author with avatar', function () {
        $author = ContentAuthor::factory()->withAvatar()->create();

        expect($author->avatar_url)->not->toBeNull();
    });

    it('creates a content taxonomy category', function () {
        $category = ContentTaxonomy::factory()->category()->create();

        expect($category)->toBeInstanceOf(ContentTaxonomy::class)
            ->and($category->type)->toBe('category');
    });

    it('creates a content taxonomy tag', function () {
        $tag = ContentTaxonomy::factory()->tag()->create();

        expect($tag->type)->toBe('tag');
    });

    it('creates content media', function () {
        $media = ContentMedia::factory()->create();

        expect($media)->toBeInstanceOf(ContentMedia::class)
            ->and($media->mime_type)->toStartWith('image/')
            ->and($media->width)->toBeInt()
            ->and($media->height)->toBeInt();
    });

    it('creates media with cdn', function () {
        $media = ContentMedia::factory()->withCdn()->create();

        expect($media->cdn_url)->toContain('cdn.host.uk.com');
    });

    it('creates a content item', function () {
        $item = ContentItem::factory()->create();

        expect($item)->toBeInstanceOf(ContentItem::class)
            ->and($item->title)->toBeString()
            ->and($item->slug)->toBeString()
            ->and($item->type)->toBeIn(['post', 'page'])
            ->and($item->status)->toBeIn(['publish', 'draft', 'pending', 'future', 'private']);
    });

    it('creates a published post', function () {
        $item = ContentItem::factory()->published()->post()->create();

        expect($item->status)->toBe('publish')
            ->and($item->type)->toBe('post')
            ->and($item->sync_status)->toBe('synced');
    });

    it('creates a draft page', function () {
        $item = ContentItem::factory()->draft()->page()->create();

        expect($item->status)->toBe('draft')
            ->and($item->type)->toBe('page');
    });

    it('creates a failed sync item', function () {
        $item = ContentItem::factory()->failed()->create();

        expect($item->sync_status)->toBe('failed')
            ->and($item->sync_error)->not->toBeNull();
    });

    it('creates a content item with rich content', function () {
        $item = ContentItem::factory()->withRichContent()->create();

        expect($item->content_html_original)->toContain('<h2>')
            ->and($item->content_json['blocks'])->toBeArray()
            ->and(count($item->content_json['blocks']))->toBeGreaterThan(3);
    });

    it('creates a webhook log', function () {
        $log = ContentWebhookLog::factory()->create();

        expect($log)->toBeInstanceOf(ContentWebhookLog::class)
            ->and($log->event_type)->toBeString()
            ->and($log->payload)->toBeArray();
    });

    it('creates a completed webhook', function () {
        $log = ContentWebhookLog::factory()->completed()->create();

        expect($log->status)->toBe('completed')
            ->and($log->processed_at)->not->toBeNull()
            ->and($log->error_message)->toBeNull();
    });

    it('creates a failed webhook', function () {
        $log = ContentWebhookLog::factory()->failed()->create();

        expect($log->status)->toBe('failed')
            ->and($log->error_message)->not->toBeNull();
    });

    it('creates multiple content items', function () {
        $workspace = Workspace::factory()->create();
        $items = ContentItem::factory()
            ->count(10)
            ->create(['workspace_id' => $workspace->id]);

        expect($items)->toHaveCount(10)
            ->and($items->first()->workspace_id)->toBe($workspace->id);
    });

    it('creates items with different statuses using sequence', function () {
        $items = ContentItem::factory()
            ->count(3)
            ->sequence(
                ['status' => 'publish'],
                ['status' => 'draft'],
                ['status' => 'pending'],
            )
            ->create();

        expect($items[0]->status)->toBe('publish')
            ->and($items[1]->status)->toBe('draft')
            ->and($items[2]->status)->toBe('pending');
    });
});

describe('Computed Property Tests', function () {
    it('returns correct status colour for content items', function () {
        $published = ContentItem::factory()->published()->create();
        $draft = ContentItem::factory()->draft()->create();
        $pending = ContentItem::factory()->pending()->create();
        $scheduled = ContentItem::factory()->scheduled()->create();

        expect($published->status_color)->toBe('green')
            ->and($draft->status_color)->toBe('yellow')
            ->and($pending->status_color)->toBe('orange')
            ->and($scheduled->status_color)->toBe('blue');
    });

    it('returns correct sync colour for content items', function () {
        $synced = ContentItem::factory()->synced()->create();
        $pendingSync = ContentItem::factory()->pendingSync()->create();
        $stale = ContentItem::factory()->stale()->create();
        $failed = ContentItem::factory()->failed()->create();

        expect($synced->sync_color)->toBe('green')
            ->and($pendingSync->sync_color)->toBe('yellow')
            ->and($stale->sync_color)->toBe('orange')
            ->and($failed->sync_color)->toBe('red');
    });

    it('returns correct type colour for content items', function () {
        $post = ContentItem::factory()->post()->create();
        $page = ContentItem::factory()->page()->create();

        expect($post->type_color)->toBe('blue')
            ->and($page->type_color)->toBe('violet');
    });

    it('returns correct icons for content item status', function () {
        $published = ContentItem::factory()->published()->create();
        $draft = ContentItem::factory()->draft()->create();

        expect($published->status_icon)->toBe('check-circle')
            ->and($draft->status_icon)->toBe('pencil');
    });

    it('returns correct icons for sync status', function () {
        $synced = ContentItem::factory()->synced()->create();
        $failed = ContentItem::factory()->failed()->create();

        expect($synced->sync_icon)->toBe('check')
            ->and($failed->sync_icon)->toBe('x-mark');
    });

    it('returns correct status colour for webhooks', function () {
        $pending = ContentWebhookLog::factory()->pending()->create();
        $completed = ContentWebhookLog::factory()->completed()->create();
        $failed = ContentWebhookLog::factory()->failed()->create();

        expect($pending->status_color)->toBe('yellow')
            ->and($completed->status_color)->toBe('green')
            ->and($failed->status_color)->toBe('red');
    });

    it('returns correct icons for webhook status', function () {
        $pending = ContentWebhookLog::factory()->pending()->create();
        $completed = ContentWebhookLog::factory()->completed()->create();
        $failed = ContentWebhookLog::factory()->failed()->create();

        expect($pending->status_icon)->toBe('clock')
            ->and($completed->status_icon)->toBe('check')
            ->and($failed->status_icon)->toBe('x-mark');
    });
});
