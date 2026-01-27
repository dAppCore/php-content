<?php

declare(strict_types=1);

namespace Core\Mod\Content\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Log;

class ContentProcessingService
{
    /**
     * WordPress classes to remove during cleaning.
     */
    protected array $wpClassPatterns = [
        '/^wp-/',
        '/^has-/',
        '/^is-/',
        '/^alignleft$/',
        '/^alignright$/',
        '/^aligncenter$/',
        '/^alignwide$/',
        '/^alignfull$/',
        '/^size-/',
        '/^attachment-/',
    ];

    /**
     * Process WordPress content into all three formats.
     */
    public function process(array $wpContent): array
    {
        $html = $wpContent['content']['rendered'] ?? $wpContent['content'] ?? '';

        return [
            'content_html_original' => $html,
            'content_html_clean' => $this->cleanHtml($html),
            'content_json' => $this->parseToJson($html),
        ];
    }

    /**
     * Clean HTML by removing WordPress-specific cruft.
     *
     * - Remove inline styles
     * - Remove WordPress classes
     * - Remove empty elements
     * - Remove block comments
     * - Preserve semantic structure
     */
    public function cleanHtml(string $html): string
    {
        if (empty($html)) {
            return '';
        }

        // Remove WordPress block comments
        $html = preg_replace('/<!--\s*\/?wp:[^>]+-->/s', '', $html);

        // Remove empty comments
        $html = preg_replace('/<!--\s*-->/s', '', $html);

        // Load into DOM
        $doc = $this->loadHtml($html);
        if (! $doc) {
            Log::warning('ContentProcessingService: Failed to parse HTML, falling back to strip_tags', [
                'html_length' => strlen($html),
                'html_preview' => substr($html, 0, 200),
            ]);

            return strip_tags($html, '<p><a><strong><em><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><img><figure><figcaption>');
        }

        $xpath = new DOMXPath($doc);

        // Remove all style attributes
        $styledElements = $xpath->query('//*[@style]');
        foreach ($styledElements as $el) {
            $el->removeAttribute('style');
        }

        // Clean WordPress classes from all elements
        $classedElements = $xpath->query('//*[@class]');
        foreach ($classedElements as $el) {
            $this->cleanClasses($el);
        }

        // Remove data-* attributes (WordPress block data)
        $allElements = $xpath->query('//*');
        foreach ($allElements as $el) {
            $attributesToRemove = [];
            foreach ($el->attributes as $attr) {
                if (str_starts_with($attr->name, 'data-')) {
                    $attributesToRemove[] = $attr->name;
                }
            }
            foreach ($attributesToRemove as $attrName) {
                $el->removeAttribute($attrName);
            }
        }

        // Remove empty paragraphs and divs
        $this->removeEmptyElements($doc, $xpath);

        // Extract body content
        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body) {
            return '';
        }

        $cleanHtml = '';
        foreach ($body->childNodes as $child) {
            $cleanHtml .= $doc->saveHTML($child);
        }

        // Final cleanup
        $cleanHtml = preg_replace('/\s+/', ' ', $cleanHtml);
        $cleanHtml = preg_replace('/>\s+</', '><', $cleanHtml);
        $cleanHtml = trim($cleanHtml);

        // Pretty format
        $cleanHtml = preg_replace('/<\/(p|div|h[1-6]|ul|ol|li|blockquote|figure)>/', "</$1>\n", $cleanHtml);

        return trim($cleanHtml);
    }

    /**
     * Parse HTML into structured JSON blocks for headless rendering.
     */
    public function parseToJson(string $html): array
    {
        if (empty($html)) {
            return ['blocks' => []];
        }

        // Remove WordPress block comments
        $html = preg_replace('/<!--\s*\/?wp:[^>]+-->/s', '', $html);

        $doc = $this->loadHtml($html);
        if (! $doc) {
            Log::warning('ContentProcessingService: Failed to parse HTML for JSON conversion, returning single block', [
                'html_length' => strlen($html),
                'html_preview' => substr($html, 0, 200),
            ]);

            return ['blocks' => [['type' => 'paragraph', 'content' => strip_tags($html)]]];
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body) {
            return ['blocks' => []];
        }

        $blocks = [];
        foreach ($body->childNodes as $node) {
            $block = $this->nodeToBlock($node, $doc);
            if ($block) {
                $blocks[] = $block;
            }
        }

        return ['blocks' => $blocks];
    }

    /**
     * Convert a DOM node to a structured block.
     */
    protected function nodeToBlock(DOMNode $node, DOMDocument $doc): ?array
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = trim($node->textContent);
            if (empty($text)) {
                return null;
            }

            return ['type' => 'text', 'content' => $text];
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return null;
        }

        /** @var DOMElement $element */
        $element = $node;
        $tagName = strtolower($element->tagName);

        return match ($tagName) {
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $this->parseHeading($element, $tagName),
            'p' => $this->parseParagraph($element, $doc),
            'ul', 'ol' => $this->parseList($element, $tagName, $doc),
            'blockquote' => $this->parseBlockquote($element, $doc),
            'figure' => $this->parseFigure($element, $doc),
            'img' => $this->parseImage($element),
            'div' => $this->parseDiv($element, $doc),
            'a' => $this->parseLink($element, $doc),
            'pre' => $this->parseCodeBlock($element),
            'hr' => ['type' => 'divider'],
            default => $this->parseGeneric($element, $doc),
        };
    }

    /**
     * Parse a heading element.
     */
    protected function parseHeading(DOMElement $element, string $tag): array
    {
        return [
            'type' => 'heading',
            'level' => (int) substr($tag, 1),
            'content' => trim($element->textContent),
            'id' => $element->getAttribute('id') ?: null,
        ];
    }

    /**
     * Parse a paragraph element.
     */
    protected function parseParagraph(DOMElement $element, DOMDocument $doc): ?array
    {
        $content = trim($element->textContent);
        if (empty($content) && ! $element->getElementsByTagName('img')->length) {
            return null;
        }

        // Check for embedded image
        $images = $element->getElementsByTagName('img');
        if ($images->length > 0) {
            $img = $images->item(0);

            return $this->parseImage($img);
        }

        return [
            'type' => 'paragraph',
            'content' => $content,
            'html' => $this->getInnerHtml($element, $doc),
        ];
    }

    /**
     * Parse a list element.
     */
    protected function parseList(DOMElement $element, string $tag, DOMDocument $doc): array
    {
        $items = [];
        foreach ($element->getElementsByTagName('li') as $li) {
            $items[] = [
                'content' => trim($li->textContent),
                'html' => $this->getInnerHtml($li, $doc),
            ];
        }

        return [
            'type' => 'list',
            'ordered' => $tag === 'ol',
            'items' => $items,
        ];
    }

    /**
     * Parse a blockquote element.
     */
    protected function parseBlockquote(DOMElement $element, DOMDocument $doc): array
    {
        $content = [];
        foreach ($element->childNodes as $child) {
            $block = $this->nodeToBlock($child, $doc);
            if ($block) {
                $content[] = $block;
            }
        }

        // Check for citation
        $cite = $element->getElementsByTagName('cite');
        $citation = $cite->length > 0 ? trim($cite->item(0)->textContent) : null;

        return [
            'type' => 'blockquote',
            'content' => $content,
            'citation' => $citation,
        ];
    }

    /**
     * Parse a figure element (usually contains image + caption).
     */
    protected function parseFigure(DOMElement $element, DOMDocument $doc): ?array
    {
        $img = $element->getElementsByTagName('img')->item(0);
        if (! $img) {
            // Could be an embed or other figure type
            return $this->parseGeneric($element, $doc);
        }

        $figcaption = $element->getElementsByTagName('figcaption')->item(0);

        return [
            'type' => 'image',
            'src' => $img->getAttribute('src'),
            'alt' => $img->getAttribute('alt'),
            'width' => $img->getAttribute('width') ?: null,
            'height' => $img->getAttribute('height') ?: null,
            'caption' => $figcaption ? trim($figcaption->textContent) : null,
            'srcset' => $img->getAttribute('srcset') ?: null,
            'sizes' => $img->getAttribute('sizes') ?: null,
        ];
    }

    /**
     * Parse a standalone image element.
     */
    protected function parseImage(DOMElement $element): array
    {
        return [
            'type' => 'image',
            'src' => $element->getAttribute('src'),
            'alt' => $element->getAttribute('alt'),
            'width' => $element->getAttribute('width') ?: null,
            'height' => $element->getAttribute('height') ?: null,
            'srcset' => $element->getAttribute('srcset') ?: null,
            'sizes' => $element->getAttribute('sizes') ?: null,
        ];
    }

    /**
     * Parse a div element (may contain groups, embeds, etc).
     */
    protected function parseDiv(DOMElement $element, DOMDocument $doc): ?array
    {
        // Check for WordPress embed block
        $class = $element->getAttribute('class');
        if (str_contains($class, 'wp-block-embed')) {
            return $this->parseEmbed($element);
        }

        // Check for group block - return children
        $children = [];
        foreach ($element->childNodes as $child) {
            $block = $this->nodeToBlock($child, $doc);
            if ($block) {
                $children[] = $block;
            }
        }

        if (count($children) === 0) {
            return null;
        }

        if (count($children) === 1) {
            return $children[0];
        }

        return [
            'type' => 'group',
            'children' => $children,
        ];
    }

    /**
     * Parse an embed (YouTube, Twitter, etc).
     */
    protected function parseEmbed(DOMElement $element): array
    {
        $iframe = $element->getElementsByTagName('iframe')->item(0);

        if ($iframe) {
            $src = $iframe->getAttribute('src');

            // Detect embed type
            $provider = 'unknown';
            if (str_contains($src, 'youtube.com') || str_contains($src, 'youtu.be')) {
                $provider = 'youtube';
            } elseif (str_contains($src, 'vimeo.com')) {
                $provider = 'vimeo';
            } elseif (str_contains($src, 'twitter.com') || str_contains($src, 'x.com')) {
                $provider = 'twitter';
            } elseif (str_contains($src, 'spotify.com')) {
                $provider = 'spotify';
            }

            return [
                'type' => 'embed',
                'provider' => $provider,
                'url' => $src,
                'width' => $iframe->getAttribute('width') ?: null,
                'height' => $iframe->getAttribute('height') ?: null,
            ];
        }

        // Check for blockquote embeds (Twitter, Instagram)
        $blockquote = $element->getElementsByTagName('blockquote')->item(0);
        if ($blockquote) {
            $class = $blockquote->getAttribute('class');
            $provider = 'unknown';
            if (str_contains($class, 'twitter')) {
                $provider = 'twitter';
            } elseif (str_contains($class, 'instagram')) {
                $provider = 'instagram';
            }

            return [
                'type' => 'embed',
                'provider' => $provider,
                'html' => $element->ownerDocument->saveHTML($blockquote),
            ];
        }

        return [
            'type' => 'embed',
            'provider' => 'unknown',
            'html' => $element->ownerDocument->saveHTML($element),
        ];
    }

    /**
     * Parse a link element.
     */
    protected function parseLink(DOMElement $element, DOMDocument $doc): array
    {
        return [
            'type' => 'link',
            'href' => $element->getAttribute('href'),
            'content' => trim($element->textContent),
            'target' => $element->getAttribute('target') ?: null,
            'rel' => $element->getAttribute('rel') ?: null,
        ];
    }

    /**
     * Parse a code block (pre element).
     */
    protected function parseCodeBlock(DOMElement $element): array
    {
        $code = $element->getElementsByTagName('code')->item(0);
        $content = $code ? $code->textContent : $element->textContent;
        $language = null;

        if ($code) {
            $class = $code->getAttribute('class');
            if (preg_match('/language-(\w+)/', $class, $matches)) {
                $language = $matches[1];
            }
        }

        return [
            'type' => 'code',
            'content' => $content,
            'language' => $language,
        ];
    }

    /**
     * Parse a generic element.
     */
    protected function parseGeneric(DOMElement $element, DOMDocument $doc): ?array
    {
        $content = trim($element->textContent);
        if (empty($content)) {
            return null;
        }

        return [
            'type' => 'html',
            'tag' => strtolower($element->tagName),
            'content' => $content,
            'html' => $this->getInnerHtml($element, $doc),
        ];
    }

    /**
     * Load HTML into a DOMDocument.
     */
    protected function loadHtml(string $html): ?DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);

        $wrappedHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>';

        if (! $doc->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            $errors = libxml_get_errors();
            if (! empty($errors)) {
                $errorMessages = array_map(fn ($e) => trim($e->message), array_slice($errors, 0, 5));
                Log::debug('ContentProcessingService: libxml errors during HTML parsing', [
                    'errors' => $errorMessages,
                    'error_count' => count($errors),
                ]);
            }
            libxml_clear_errors();

            return null;
        }

        // Log any warnings/errors that occurred even if parsing succeeded
        $errors = libxml_get_errors();
        if (! empty($errors)) {
            $errorMessages = array_map(fn ($e) => trim($e->message), array_slice($errors, 0, 3));
            Log::debug('ContentProcessingService: HTML parsed with warnings', [
                'warning_count' => count($errors),
                'warnings' => $errorMessages,
            ]);
        }
        libxml_clear_errors();

        return $doc;
    }

    /**
     * Clean WordPress classes from an element.
     */
    protected function cleanClasses(DOMElement $element): void
    {
        $classes = explode(' ', $element->getAttribute('class'));
        $cleanClasses = [];

        foreach ($classes as $class) {
            $class = trim($class);
            if (empty($class)) {
                continue;
            }

            $isWpClass = false;
            foreach ($this->wpClassPatterns as $pattern) {
                if (preg_match($pattern, $class)) {
                    $isWpClass = true;
                    break;
                }
            }

            if (! $isWpClass) {
                $cleanClasses[] = $class;
            }
        }

        if (empty($cleanClasses)) {
            $element->removeAttribute('class');
        } else {
            $element->setAttribute('class', implode(' ', $cleanClasses));
        }
    }

    /**
     * Remove empty elements from the document.
     */
    protected function removeEmptyElements(DOMDocument $doc, DOMXPath $xpath): void
    {
        $emptyTags = ['p', 'div', 'span'];

        foreach ($emptyTags as $tag) {
            $elements = $xpath->query("//{$tag}");
            $toRemove = [];

            foreach ($elements as $el) {
                $content = trim($el->textContent);
                $hasChildren = $el->getElementsByTagName('img')->length > 0
                    || $el->getElementsByTagName('iframe')->length > 0;

                if (empty($content) && ! $hasChildren) {
                    $toRemove[] = $el;
                }
            }

            foreach ($toRemove as $el) {
                if ($el->parentNode) {
                    $el->parentNode->removeChild($el);
                }
            }
        }
    }

    /**
     * Get the inner HTML of an element.
     */
    protected function getInnerHtml(DOMElement $element, DOMDocument $doc): string
    {
        $inner = '';
        foreach ($element->childNodes as $child) {
            $inner .= $doc->saveHTML($child);
        }

        return trim($inner);
    }
}
