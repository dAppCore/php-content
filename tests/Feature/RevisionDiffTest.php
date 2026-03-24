<?php

declare(strict_types=1);

use Core\Mod\Content\Models\ContentItem;
use Core\Mod\Content\Models\ContentRevision;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('ContentRevision getDiff', function () {
    it('returns diff with no previous revision', function () {
        $item = ContentItem::factory()->create([
            'title' => 'Test Title',
            'excerpt' => 'Test excerpt',
            'content_html' => '<p>Test content</p>',
        ]);

        $revision = ContentRevision::createFromContentItem($item);

        $diff = $revision->getDiff();

        expect($diff['has_previous'])->toBeFalse()
            ->and($diff['from_revision'])->toBeNull()
            ->and($diff['to_revision'])->toBe(1)
            ->and($diff['title']['new'])->toBe('Test Title');
    });

    it('detects title changes between revisions', function () {
        $item = ContentItem::factory()->create([
            'title' => 'Original Title',
            'content_html' => '<p>Content</p>',
        ]);

        $revision1 = ContentRevision::createFromContentItem($item);

        $item->update(['title' => 'Updated Title']);
        $revision2 = ContentRevision::createFromContentItem($item);

        $diff = $revision2->getDiff();

        expect($diff['has_previous'])->toBeTrue()
            ->and($diff['from_revision'])->toBe(1)
            ->and($diff['to_revision'])->toBe(2)
            ->and($diff['title']['changed'])->toBeTrue()
            ->and($diff['title']['old'])->toBe('Original Title')
            ->and($diff['title']['new'])->toBe('Updated Title')
            ->and($diff['title']['inline'])->toContain('diff-removed')
            ->and($diff['title']['inline'])->toContain('diff-added');
    });

    it('detects content changes between revisions', function () {
        $item = ContentItem::factory()->create([
            'title' => 'Test',
            'content_html' => '<p>First line</p><p>Second line</p>',
        ]);

        $revision1 = ContentRevision::createFromContentItem($item);

        $item->update(['content_html' => '<p>First line</p><p>Modified second line</p><p>Third line</p>']);
        $revision2 = ContentRevision::createFromContentItem($item);

        $diff = $revision2->getDiff();

        expect($diff['content']['changed'])->toBeTrue()
            ->and($diff['content']['lines'])->toBeArray()
            ->and(count($diff['content']['lines']))->toBeGreaterThan(0);
    });

    it('shows no changes when content is identical', function () {
        $item = ContentItem::factory()->create([
            'title' => 'Same Title',
            'content_html' => '<p>Same content</p>',
        ]);

        $revision1 = ContentRevision::createFromContentItem($item, changeType: ContentRevision::CHANGE_EDIT);
        $revision2 = ContentRevision::createFromContentItem($item, changeType: ContentRevision::CHANGE_AUTOSAVE);

        $diff = $revision2->getDiff();

        expect($diff['title']['changed'])->toBeFalse()
            ->and($diff['content']['changed'])->toBeFalse();
    });

    it('compares two specific revisions', function () {
        $item = ContentItem::factory()->create(['title' => 'Version 1']);
        $rev1 = ContentRevision::createFromContentItem($item);

        $item->update(['title' => 'Version 2']);
        $rev2 = ContentRevision::createFromContentItem($item);

        $item->update(['title' => 'Version 3']);
        $rev3 = ContentRevision::createFromContentItem($item);

        // Compare revision 1 to revision 3 (skipping 2)
        $diff = ContentRevision::compare($rev1->id, $rev3->id);

        expect($diff['from_revision'])->toBe(1)
            ->and($diff['to_revision'])->toBe(3)
            ->and($diff['title']['old'])->toBe('Version 1')
            ->and($diff['title']['new'])->toBe('Version 3');
    });

    it('tracks word count differences', function () {
        $item = ContentItem::factory()->create([
            'content_html' => '<p>One two three</p>',
        ]);

        $revision1 = ContentRevision::createFromContentItem($item);

        $item->update(['content_html' => '<p>One two three four five six seven</p>']);
        $revision2 = ContentRevision::createFromContentItem($item);

        $diff = $revision2->getDiff();

        expect($diff['word_count']['diff'])->toBeGreaterThan(0)
            ->and($diff['word_count']['old'])->toBeLessThan($diff['word_count']['new']);
    });

    it('detects status changes', function () {
        $item = ContentItem::factory()->create(['status' => 'draft']);
        $revision1 = ContentRevision::createFromContentItem($item);

        $item->update(['status' => 'publish']);
        $revision2 = ContentRevision::createFromContentItem($item, changeType: ContentRevision::CHANGE_PUBLISH);

        $diff = $revision2->getDiff();

        expect($diff['status']['changed'])->toBeTrue()
            ->and($diff['status']['old'])->toBe('draft')
            ->and($diff['status']['new'])->toBe('publish');
    });

    it('generates inline diff for short text', function () {
        $item = ContentItem::factory()->create(['excerpt' => 'A short text']);
        $revision1 = ContentRevision::createFromContentItem($item);

        $item->update(['excerpt' => 'A longer text here']);
        $revision2 = ContentRevision::createFromContentItem($item);

        $diff = $revision2->getDiff();

        expect($diff['excerpt']['changed'])->toBeTrue()
            ->and($diff['excerpt']['inline'])->toContain('diff-');
    });

    it('handles empty to populated content', function () {
        $item = ContentItem::factory()->create(['excerpt' => '']);
        $revision1 = ContentRevision::createFromContentItem($item);

        $item->update(['excerpt' => 'New excerpt content']);
        $revision2 = ContentRevision::createFromContentItem($item);

        $diff = $revision2->getDiff();

        expect($diff['excerpt']['changed'])->toBeTrue()
            ->and($diff['excerpt']['inline'])->toContain('diff-added');
    });

    it('handles populated to empty content', function () {
        $item = ContentItem::factory()->create(['excerpt' => 'Original excerpt']);
        $revision1 = ContentRevision::createFromContentItem($item);

        $item->update(['excerpt' => '']);
        $revision2 = ContentRevision::createFromContentItem($item);

        $diff = $revision2->getDiff();

        expect($diff['excerpt']['changed'])->toBeTrue()
            ->and($diff['excerpt']['inline'])->toContain('diff-removed');
    });
});

describe('ContentRevision getDiffSummary', function () {
    it('returns null for first revision', function () {
        $item = ContentItem::factory()->create();
        $revision = ContentRevision::createFromContentItem($item);

        expect($revision->getDiffSummary())->toBeNull();
    });

    it('returns boolean flags for changes', function () {
        $item = ContentItem::factory()->create([
            'title' => 'Original',
            'content_html' => '<p>Content</p>',
        ]);

        ContentRevision::createFromContentItem($item);

        $item->update(['title' => 'Changed']);
        $revision2 = ContentRevision::createFromContentItem($item);

        $summary = $revision2->getDiffSummary();

        expect($summary)->toBeArray()
            ->and($summary['title_changed'])->toBeTrue()
            ->and($summary['content_changed'])->toBeFalse();
    });
});
