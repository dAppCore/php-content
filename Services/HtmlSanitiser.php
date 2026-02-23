<?php

declare(strict_types=1);

namespace Core\Mod\Content\Services;

use HTMLPurifier;
use HTMLPurifier_Config;
use RuntimeException;

/**
 * HTML sanitiser for content rendering.
 *
 * Uses HTMLPurifier to remove XSS vectors while preserving safe HTML elements.
 * This is a security-critical service - all user-generated HTML content must
 * be sanitised before rendering.
 *
 * @see https://htmlpurifier.org/
 */
class HtmlSanitiser
{
    private HTMLPurifier $purifier;

    /**
     * Create a new HTML sanitiser instance.
     *
     * @throws RuntimeException If HTMLPurifier is not installed
     */
    public function __construct()
    {
        if (! class_exists(HTMLPurifier::class)) {
            throw new RuntimeException(
                'HTMLPurifier is required for HTML sanitisation. '.
                'Install it with: composer require ezyang/htmlpurifier'
            );
        }

        $config = HTMLPurifier_Config::createDefault();

        // Allow a safe set of HTML5 elements for content rendering
        $config->set('HTML.Allowed', implode(',', [
            // Structure
            'div[id|class]',
            'span[id|class]',
            'section[id|class]',
            'article[id|class]',

            // Text
            'h1[id|class]',
            'h2[id|class]',
            'h3[id|class]',
            'h4[id|class]',
            'h5[id|class]',
            'h6[id|class]',
            'p[id|class]',
            'br',
            'hr[id|class]',
            'strong',
            'em',
            'b',
            'i',
            'u',
            'small',
            'mark',
            'del',
            'ins',
            'sub',
            'sup',
            'code',
            'pre[id|class]',
            'blockquote[id|class]',

            // Lists
            'ul[id|class]',
            'ol[id|class]',
            'li[id|class]',

            // Links and media
            'a[href|id|class|target|rel]',
            'img[src|alt|width|height|id|class]',
            'figure[id|class]',
            'figcaption[id|class]',

            // Tables
            'table[id|class]',
            'thead[id|class]',
            'tbody[id|class]',
            'tr[id|class]',
            'th[id|class|colspan|rowspan]',
            'td[id|class|colspan|rowspan]',
        ]));

        // Safe link targets
        $config->set('Attr.AllowedFrameTargets', ['_blank', '_self']);

        // Add rel="noopener" to external links for security
        $config->set('HTML.Nofollow', true);
        $config->set('HTML.TargetNoopener', true);

        // Disable cache to allow custom HTML definitions
        $config->set('Cache.DefinitionImpl', null);

        // Register HTML5 elements that HTMLPurifier doesn't know about
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $def->addElement('section', 'Block', 'Flow', 'Common');
            $def->addElement('article', 'Block', 'Flow', 'Common');
            $def->addElement('figure', 'Block', 'Flow', 'Common');
            $def->addElement('figcaption', 'Inline', 'Flow', 'Common');
            $def->addElement('mark', 'Inline', 'Inline', 'Common');
        }

        // Safe URI schemes only
        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
            'mailto' => true,
            'tel' => true,
        ]);

        // Do not allow data: URIs (can contain XSS)
        $config->set('URI.DisableExternalResources', false);
        $config->set('URI.DisableResources', false);

        $this->purifier = new HTMLPurifier($config);
    }

    /**
     * Sanitise HTML content to prevent XSS attacks.
     *
     * This method removes dangerous HTML, JavaScript, and CSS while preserving
     * safe formatting elements. Always use this before rendering user content.
     *
     * @param  string  $html  The raw HTML content to sanitise
     * @return string The sanitised HTML, safe for rendering
     */
    public function sanitise(string $html): string
    {
        if (empty($html)) {
            return '';
        }

        return $this->purifier->purify($html);
    }

    /**
     * Check if HTMLPurifier is available.
     *
     * Use this method to verify the dependency is installed before attempting
     * to create a sanitiser instance.
     */
    public static function isAvailable(): bool
    {
        return class_exists(HTMLPurifier::class);
    }
}
