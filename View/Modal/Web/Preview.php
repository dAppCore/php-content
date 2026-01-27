<?php

declare(strict_types=1);

namespace Core\Mod\Content\View\Modal\Web;

use Core\Mod\Tenant\Models\Workspace;
use Livewire\Component;
use Core\Mod\Content\Models\ContentItem;

/**
 * Preview - Render draft/unpublished content with preview token.
 *
 * Shows a preview banner indicating the content is not yet published,
 * with the option to compare with the published version if one exists.
 */
class Preview extends Component
{
    public array $workspace = [];

    public array $content = [];

    public bool $notFound = false;

    public bool $invalidToken = false;

    public bool $isPublished = false;

    public ?string $expiresIn = null;

    public string $previewType = 'post'; // post or page

    public function mount(int $item): void
    {
        // Get token from query string
        $token = request()->query('token');
        $this->loadPreview($item, $token);
    }

    protected function loadPreview(int $itemId, ?string $token): void
    {
        $contentItem = ContentItem::with(['workspace', 'author', 'featuredMedia', 'taxonomies'])
            ->find($itemId);

        if (! $contentItem) {
            $this->notFound = true;

            return;
        }

        // Load workspace data
        $workspace = $contentItem->workspace;
        if (! $workspace) {
            $this->notFound = true;

            return;
        }

        $this->workspace = [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'domain' => $workspace->domain,
        ];

        $this->previewType = $contentItem->type;
        $this->isPublished = $contentItem->status === 'publish';

        // If content is published, no token needed - just show it
        if ($this->isPublished) {
            $this->content = $contentItem->toRenderArray();
            $this->content['preview_status'] = 'published';

            return;
        }

        // For unpublished content, validate the preview token
        if (! $contentItem->isValidPreviewToken($token)) {
            $this->invalidToken = true;

            return;
        }

        // Token is valid, show the preview
        $this->expiresIn = $contentItem->getPreviewTokenTimeRemaining();
        $this->content = $contentItem->toRenderArray();
        $this->content['preview_status'] = $contentItem->status;
    }

    public function render()
    {
        if ($this->notFound) {
            abort(404);
        }

        if ($this->invalidToken) {
            return view('content::web.preview-invalid')
                ->layout('shared::layouts.satellite', [
                    'title' => 'Preview Expired',
                    'workspace' => $this->workspace,
                ]);
        }

        return view('content::web.preview', [
            'content' => $this->content,
            'workspace' => $this->workspace,
            'expiresIn' => $this->expiresIn,
            'isPublished' => $this->isPublished,
            'previewType' => $this->previewType,
        ])->layout('shared::layouts.satellite', [
            'title' => ($this->content['title']['rendered'] ?? 'Preview').' | '.$this->workspace['name'],
            'workspace' => $this->workspace,
        ]);
    }
}
