<?php

declare(strict_types=1);

namespace Core\Mod\Content\Controllers\Api;

use Core\Front\Controller;
use Core\Api\Concerns\HasApiResponses;
use Core\Api\Concerns\ResolvesWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Core\Mod\Content\Models\ContentMedia;

/**
 * Content Media API Controller
 *
 * Upload and manage media files for content.
 */
class ContentMediaController extends Controller
{
    use HasApiResponses;
    use ResolvesWorkspace;

    /**
     * Allowed MIME types for upload.
     */
    protected array $allowedTypes = [
        // Images
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        // Documents
        'application/pdf',
    ];

    /**
     * Max file size in bytes (10MB).
     */
    protected int $maxFileSize = 10 * 1024 * 1024;

    /**
     * List media for the workspace.
     *
     * GET /api/v1/content/media
     */
    public function index(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        if (! $workspace) {
            return $this->noWorkspaceResponse();
        }

        $query = ContentMedia::forWorkspace($workspace->id);

        // Filter by type
        if ($request->has('type')) {
            $type = $request->input('type');
            if ($type === 'image') {
                $query->images();
            } elseif ($type === 'document') {
                $query->where('mime_type', 'application/pdf');
            }
        }

        $media = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => $media->items(),
            'meta' => [
                'current_page' => $media->currentPage(),
                'last_page' => $media->lastPage(),
                'per_page' => $media->perPage(),
                'total' => $media->total(),
            ],
        ]);
    }

    /**
     * Upload a media file.
     *
     * POST /api/v1/content/media
     */
    public function store(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        if (! $workspace) {
            return $this->noWorkspaceResponse();
        }

        $validated = $request->validate([
            'file' => 'required|file|max:10240', // 10MB
            'title' => 'nullable|string|max:255',
            'alt_text' => 'nullable|string|max:500',
            'caption' => 'nullable|string|max:1000',
        ]);

        $file = $request->file('file');
        $mimeType = $file->getMimeType();

        // Validate MIME type
        if (! in_array($mimeType, $this->allowedTypes, true)) {
            return $this->validationErrorResponse([
                'file' => ['File type not allowed. Allowed types: JPEG, PNG, GIF, WebP, SVG, PDF.'],
            ]);
        }

        // Generate unique filename
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid().'.'.$extension;

        // Store in workspace-specific path
        $path = sprintf(
            'content/%d/%s/%s',
            $workspace->id,
            now()->format('Y/m'),
            $filename
        );

        // Store file
        Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));

        // Get image dimensions if applicable
        $width = null;
        $height = null;

        if (str_starts_with($mimeType, 'image/') && $mimeType !== 'image/svg+xml') {
            $imageInfo = @getimagesize($file->getRealPath());
            if ($imageInfo !== false) {
                [$width, $height] = $imageInfo;
            }
        }

        // Create media record
        $media = ContentMedia::create([
            'workspace_id' => $workspace->id,
            'wp_id' => null,
            'title' => $validated['title'] ?? $file->getClientOriginalName(),
            'filename' => $filename,
            'mime_type' => $mimeType,
            'file_size' => $file->getSize(),
            'source_url' => Storage::disk('public')->url($path),
            'cdn_url' => null,
            'width' => $width,
            'height' => $height,
            'alt_text' => $validated['alt_text'] ?? null,
            'caption' => $validated['caption'] ?? null,
            'sizes' => null,
        ]);

        return $this->createdResponse([
            'id' => $media->id,
            'url' => $media->url,
            'filename' => $media->filename,
            'mime_type' => $media->mime_type,
            'file_size' => $media->file_size,
            'width' => $media->width,
            'height' => $media->height,
            'title' => $media->title,
            'alt_text' => $media->alt_text,
        ], 'Media uploaded successfully.');
    }

    /**
     * Get a specific media item.
     *
     * GET /api/v1/content/media/{media}
     */
    public function show(Request $request, ContentMedia $media): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        if ($media->workspace_id !== $workspace?->id) {
            return $this->accessDeniedResponse();
        }

        return response()->json([
            'data' => $media,
        ]);
    }

    /**
     * Update media metadata.
     *
     * PUT /api/v1/content/media/{media}
     */
    public function update(Request $request, ContentMedia $media): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        if ($media->workspace_id !== $workspace?->id) {
            return $this->accessDeniedResponse();
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'alt_text' => 'nullable|string|max:500',
            'caption' => 'nullable|string|max:1000',
        ]);

        $media->update($validated);

        return response()->json([
            'message' => 'Media updated successfully.',
            'data' => $media,
        ]);
    }

    /**
     * Delete a media item.
     *
     * DELETE /api/v1/content/media/{media}
     */
    public function destroy(Request $request, ContentMedia $media): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        if ($media->workspace_id !== $workspace?->id) {
            return $this->accessDeniedResponse();
        }

        // Delete file from storage if it's a local upload (not WordPress)
        if ($media->wp_id === null && $media->source_url) {
            $path = str_replace(Storage::disk('public')->url(''), '', $media->source_url);
            Storage::disk('public')->delete($path);
        }

        $media->delete();

        return $this->successResponse('Media deleted successfully.');
    }
}
