<?php

declare(strict_types=1);

namespace Core\Mod\Content\Tests\Unit;

use Core\Mod\Content\Services\HtmlSanitiser;
use Tests\TestCase;

/**
 * Security tests for HTML sanitisation.
 *
 * These tests verify that XSS attack vectors are properly neutralised
 * while preserving safe HTML formatting.
 */
class HtmlSanitiserTest extends TestCase
{
    protected HtmlSanitiser $sanitiser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitiser = new HtmlSanitiser;
    }

    // -------------------------------------------------------------------------
    // XSS Attack Prevention Tests
    // -------------------------------------------------------------------------

    public function test_removes_script_tags(): void
    {
        $malicious = '<p>Hello</p><script>alert("XSS")</script><p>World</p>';
        $result = $this->sanitiser->sanitise($malicious);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringContainsString('<p>Hello</p>', $result);
        $this->assertStringContainsString('<p>World</p>', $result);
    }

    public function test_removes_onclick_attributes(): void
    {
        $malicious = '<a href="#" onclick="alert(\'XSS\')">Click me</a>';
        $result = $this->sanitiser->sanitise($malicious);

        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringContainsString('Click me', $result);
    }

    public function test_removes_onerror_attributes(): void
    {
        $malicious = '<img src="x" onerror="alert(\'XSS\')">';
        $result = $this->sanitiser->sanitise($malicious);

        $this->assertStringNotContainsString('onerror', $result);
        $this->assertStringNotContainsString('alert', $result);
    }

    public function test_removes_onload_attributes(): void
    {
        $malicious = '<body onload="alert(\'XSS\')">';
        $result = $this->sanitiser->sanitise($malicious);

        $this->assertStringNotContainsString('onload', $result);
        $this->assertStringNotContainsString('alert', $result);
    }

    public function test_removes_javascript_protocol_in_href(): void
    {
        $malicious = '<a href="javascript:alert(\'XSS\')">Click me</a>';
        $result = $this->sanitiser->sanitise($malicious);

        $this->assertStringNotContainsString('javascript:', $result);
        $this->assertStringContainsString('Click me', $result);
    }

    public function test_removes_javascript_protocol_in_src(): void
    {
        $malicious = '<img src="javascript:alert(\'XSS\')">';
        $result = $this->sanitiser->sanitise($malicious);

        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function test_removes_data_uri_xss(): void
    {
        $malicious = '<a href="data:text/html,<script>alert(\'XSS\')</script>">Click</a>';
        $result = $this->sanitiser->sanitise($malicious);

        $this->assertStringNotContainsString('data:text/html', $result);
        $this->assertStringNotContainsString('<script>', $result);
    }

    public function test_removes_style_expression_xss(): void
    {
        $malicious = '<div style="background:url(javascript:alert(\'XSS\'))">Test</div>';
        $result = $this->sanitiser->sanitise($malicious);

        $this->assertStringNotContainsString('javascript:', $result);
        $this->assertStringContainsString('Test', $result);
    }

    public function test_removes_svg_xss(): void
    {
        $malicious = '<svg onload="alert(\'XSS\')"><circle r="50"/></svg>';
        $result = $this->sanitiser->sanitise($malicious);

        $this->assertStringNotContainsString('<svg', $result);
        $this->assertStringNotContainsString('onload', $result);
    }

    public function test_removes_iframe_by_default(): void
    {
        $malicious = '<iframe src="https://evil.com"></iframe>';
        $result = $this->sanitiser->sanitise($malicious);

        $this->assertStringNotContainsString('<iframe', $result);
    }

    public function test_removes_form_action_xss(): void
    {
        $malicious = '<form action="javascript:alert(\'XSS\')"><input type="submit"></form>';
        $result = $this->sanitiser->sanitise($malicious);

        $this->assertStringNotContainsString('javascript:', $result);
        $this->assertStringNotContainsString('<form', $result);
    }

    public function test_removes_meta_refresh_xss(): void
    {
        $malicious = '<meta http-equiv="refresh" content="0;url=javascript:alert(\'XSS\')">';
        $result = $this->sanitiser->sanitise($malicious);

        $this->assertStringNotContainsString('<meta', $result);
        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function test_removes_object_tag(): void
    {
        $malicious = '<object data="data:text/html,<script>alert(\'XSS\')</script>"></object>';
        $result = $this->sanitiser->sanitise($malicious);

        $this->assertStringNotContainsString('<object', $result);
        $this->assertStringNotContainsString('<script>', $result);
    }

    public function test_removes_embed_tag(): void
    {
        $malicious = '<embed src="javascript:alert(\'XSS\')">';
        $result = $this->sanitiser->sanitise($malicious);

        $this->assertStringNotContainsString('<embed', $result);
        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function test_removes_base_tag(): void
    {
        $malicious = '<base href="javascript:alert(\'XSS\')//"/>';
        $result = $this->sanitiser->sanitise($malicious);

        $this->assertStringNotContainsString('<base', $result);
    }

    // -------------------------------------------------------------------------
    // Safe HTML Preservation Tests
    // -------------------------------------------------------------------------

    public function test_preserves_paragraphs(): void
    {
        $html = '<p>Hello World</p>';
        $result = $this->sanitiser->sanitise($html);

        $this->assertStringContainsString('<p>Hello World</p>', $result);
    }

    public function test_preserves_headings(): void
    {
        $html = '<h1>Title</h1><h2>Subtitle</h2><h3>Section</h3>';
        $result = $this->sanitiser->sanitise($html);

        $this->assertStringContainsString('<h1>Title</h1>', $result);
        $this->assertStringContainsString('<h2>Subtitle</h2>', $result);
        $this->assertStringContainsString('<h3>Section</h3>', $result);
    }

    public function test_preserves_formatting(): void
    {
        $html = '<p><strong>Bold</strong> and <em>italic</em> and <u>underline</u></p>';
        $result = $this->sanitiser->sanitise($html);

        $this->assertStringContainsString('<strong>Bold</strong>', $result);
        $this->assertStringContainsString('<em>italic</em>', $result);
        $this->assertStringContainsString('<u>underline</u>', $result);
    }

    public function test_preserves_lists(): void
    {
        $html = '<ul><li>Item 1</li><li>Item 2</li></ul>';
        $result = $this->sanitiser->sanitise($html);

        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<li>Item 1</li>', $result);
        $this->assertStringContainsString('<li>Item 2</li>', $result);
    }

    public function test_preserves_safe_links(): void
    {
        $html = '<a href="https://example.com">Link</a>';
        $result = $this->sanitiser->sanitise($html);

        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringContainsString('Link</a>', $result);
    }

    public function test_preserves_mailto_links(): void
    {
        $html = '<a href="mailto:test@example.com">Email</a>';
        $result = $this->sanitiser->sanitise($html);

        $this->assertStringContainsString('mailto:test@example.com', $result);
    }

    public function test_preserves_tel_links(): void
    {
        $html = '<a href="tel:+1234567890">Call</a>';
        $result = $this->sanitiser->sanitise($html);

        $this->assertStringContainsString('tel:+1234567890', $result);
    }

    public function test_preserves_safe_images(): void
    {
        $html = '<img src="https://example.com/image.jpg" alt="Test image">';
        $result = $this->sanitiser->sanitise($html);

        $this->assertStringContainsString('src="https://example.com/image.jpg"', $result);
        $this->assertStringContainsString('alt="Test image"', $result);
    }

    public function test_preserves_tables(): void
    {
        $html = '<table><tr><th>Header</th></tr><tr><td>Data</td></tr></table>';
        $result = $this->sanitiser->sanitise($html);

        $this->assertStringContainsString('<table>', $result);
        $this->assertStringContainsString('<th>Header</th>', $result);
        $this->assertStringContainsString('<td>Data</td>', $result);
    }

    public function test_preserves_code_blocks(): void
    {
        $html = '<pre><code>function test() {}</code></pre>';
        $result = $this->sanitiser->sanitise($html);

        $this->assertStringContainsString('<pre>', $result);
        $this->assertStringContainsString('<code>', $result);
        $this->assertStringContainsString('function test() {}', $result);
    }

    public function test_preserves_blockquotes(): void
    {
        $html = '<blockquote>A famous quote</blockquote>';
        $result = $this->sanitiser->sanitise($html);

        $this->assertStringContainsString('<blockquote>A famous quote</blockquote>', $result);
    }

    public function test_preserves_id_and_class_attributes(): void
    {
        $html = '<div id="main" class="container"><p class="intro">Content</p></div>';
        $result = $this->sanitiser->sanitise($html);

        $this->assertStringContainsString('id="main"', $result);
        $this->assertStringContainsString('class="container"', $result);
        $this->assertStringContainsString('class="intro"', $result);
    }

    // -------------------------------------------------------------------------
    // Edge Cases
    // -------------------------------------------------------------------------

    public function test_handles_empty_string(): void
    {
        $result = $this->sanitiser->sanitise('');

        $this->assertSame('', $result);
    }

    public function test_handles_plain_text(): void
    {
        $text = 'Just plain text without any HTML';
        $result = $this->sanitiser->sanitise($text);

        $this->assertSame($text, $result);
    }

    public function test_handles_unicode_content(): void
    {
        $html = '<p>Caf?? au lait and ????????</p>';
        $result = $this->sanitiser->sanitise($html);

        $this->assertStringContainsString('Caf??', $result);
        $this->assertStringContainsString('????????', $result);
    }

    public function test_handles_nested_xss_attempts(): void
    {
        $malicious = '<div><p onclick="alert(1)"><a href="javascript:void(0)" onmouseover="alert(2)">Text</a></p></div>';
        $result = $this->sanitiser->sanitise($malicious);

        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('onmouseover', $result);
        $this->assertStringNotContainsString('javascript:', $result);
        $this->assertStringContainsString('Text', $result);
    }

    public function test_is_available_returns_true(): void
    {
        // HTMLPurifier should be installed as a required dependency
        $this->assertTrue(HtmlSanitiser::isAvailable());
    }
}
