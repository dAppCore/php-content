<?php

declare(strict_types=1);

namespace Core\Mod\Content\Tests\Feature;

use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Core\Mod\Content\Enums\ContentType;
use Core\Mod\Content\Models\ContentItem;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContentPreviewTest extends TestCase
{
    protected User $user;

    protected Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create([
            'user_id' => $this->user->id,
        ]);
    }

    #[Test]
    public function it_generates_preview_token_for_draft_content(): void
    {
        $item = ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'draft',
            'content_type' => ContentType::NATIVE,
        ]);

        $this->assertNull($item->preview_token);
        $this->assertNull($item->preview_expires_at);

        $token = $item->generatePreviewToken(24);

        $item->refresh();

        $this->assertNotNull($item->preview_token);
        $this->assertNotNull($item->preview_expires_at);
        $this->assertEquals($token, $item->preview_token);
        $this->assertTrue($item->preview_expires_at->isFuture());
    }

    #[Test]
    public function it_validates_preview_token_correctly(): void
    {
        $item = ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'draft',
            'content_type' => ContentType::NATIVE,
        ]);

        $token = $item->generatePreviewToken(24);

        // Valid token
        $this->assertTrue($item->isValidPreviewToken($token));

        // Invalid token
        $this->assertFalse($item->isValidPreviewToken('invalid-token'));
        $this->assertFalse($item->isValidPreviewToken(null));
    }

    #[Test]
    public function it_rejects_expired_preview_token(): void
    {
        $item = ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'draft',
            'content_type' => ContentType::NATIVE,
        ]);

        // Generate token with 1 hour expiry
        $token = $item->generatePreviewToken(1);

        // Manually expire the token
        $item->update([
            'preview_expires_at' => now()->subHour(),
        ]);

        $this->assertFalse($item->isValidPreviewToken($token));
        $this->assertFalse($item->hasValidPreviewToken());
    }

    #[Test]
    public function it_revokes_preview_token(): void
    {
        $item = ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'draft',
            'content_type' => ContentType::NATIVE,
        ]);

        $token = $item->generatePreviewToken(24);
        $this->assertTrue($item->hasValidPreviewToken());

        $item->revokePreviewToken();
        $item->refresh();

        $this->assertNull($item->preview_token);
        $this->assertNull($item->preview_expires_at);
        $this->assertFalse($item->isValidPreviewToken($token));
    }

    #[Test]
    public function it_generates_correct_preview_url(): void
    {
        $item = ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'draft',
            'content_type' => ContentType::NATIVE,
        ]);

        $url = $item->getPreviewUrl();

        $this->assertStringContains('/content/preview/'.$item->id, $url);
        $this->assertStringContains('token=', $url);
    }

    #[Test]
    public function it_identifies_previewable_statuses(): void
    {
        $draftItem = ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'draft',
            'content_type' => ContentType::NATIVE,
        ]);

        $publishedItem = ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'publish',
            'content_type' => ContentType::NATIVE,
        ]);

        $scheduledItem = ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'future',
            'content_type' => ContentType::NATIVE,
        ]);

        $privateItem = ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'private',
            'content_type' => ContentType::NATIVE,
        ]);

        $this->assertTrue($draftItem->isPreviewable());
        $this->assertFalse($publishedItem->isPreviewable());
        $this->assertTrue($scheduledItem->isPreviewable());
        $this->assertTrue($privateItem->isPreviewable());
    }

    #[Test]
    public function preview_page_shows_draft_content_with_valid_token(): void
    {
        $item = ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'draft',
            'content_type' => ContentType::NATIVE,
            'title' => 'Test Draft Article',
            'content_html' => '<p>This is draft content.</p>',
        ]);

        $token = $item->generatePreviewToken(24);

        $response = $this->get("/content/preview/{$item->id}?token={$token}");

        $response->assertOk();
        $response->assertSee('Test Draft Article');
        $response->assertSee('Preview Mode');
    }

    #[Test]
    public function preview_page_rejects_invalid_token(): void
    {
        $item = ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'draft',
            'content_type' => ContentType::NATIVE,
            'title' => 'Test Draft Article',
        ]);

        $response = $this->get("/content/preview/{$item->id}?token=invalid-token");

        $response->assertOk();
        $response->assertSee('Preview Link Expired');
    }

    #[Test]
    public function preview_page_shows_published_content_without_banner(): void
    {
        $item = ContentItem::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'publish',
            'content_type' => ContentType::NATIVE,
            'title' => 'Published Article',
            'content_html' => '<p>This is published content.</p>',
        ]);

        $token = $item->generatePreviewToken(24);

        $response = $this->get("/content/preview/{$item->id}?token={$token}");

        $response->assertOk();
        $response->assertSee('Published Article');
        $response->assertDontSee('Preview Mode');
    }

    protected function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }
}
