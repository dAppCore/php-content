<?php

declare(strict_types=1);

namespace Core\Content\Tests\Unit;

use Core\Front\Mcp\Contracts\McpToolHandler;
use Core\Content\Mcp\Handlers\ContentCreateHandler;
use Core\Content\Mcp\Handlers\ContentDeleteHandler;
use Core\Content\Mcp\Handlers\ContentListHandler;
use Core\Content\Mcp\Handlers\ContentReadHandler;
use Core\Content\Mcp\Handlers\ContentSearchHandler;
use Core\Content\Mcp\Handlers\ContentTaxonomiesHandler;
use Core\Content\Mcp\Handlers\ContentUpdateHandler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Content MCP handlers.
 *
 * Tests schema definitions and interface compliance.
 */
class McpHandlersTest extends TestCase
{
    /**
     * @dataProvider handlerClassProvider
     */
    public function test_handler_implements_mcp_tool_handler(string $handlerClass): void
    {
        $this->assertTrue(
            is_a($handlerClass, McpToolHandler::class, true),
            "{$handlerClass} must implement McpToolHandler"
        );
    }

    /**
     * @dataProvider handlerClassProvider
     */
    public function test_handler_schema_has_required_fields(string $handlerClass): void
    {
        $schema = $handlerClass::schema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('name', $schema);
        $this->assertArrayHasKey('description', $schema);
        $this->assertArrayHasKey('inputSchema', $schema);

        $this->assertIsString($schema['name']);
        $this->assertIsString($schema['description']);
        $this->assertIsArray($schema['inputSchema']);

        // Tool name should be snake_case
        $this->assertMatchesRegularExpression(
            '/^[a-z][a-z0-9_]*$/',
            $schema['name'],
            "Tool name must be snake_case: {$schema['name']}"
        );
    }

    /**
     * @dataProvider handlerClassProvider
     */
    public function test_handler_input_schema_has_properties(string $handlerClass): void
    {
        $schema = $handlerClass::schema();
        $inputSchema = $schema['inputSchema'];

        $this->assertArrayHasKey('type', $inputSchema);
        $this->assertEquals('object', $inputSchema['type']);
        $this->assertArrayHasKey('properties', $inputSchema);
        $this->assertIsArray($inputSchema['properties']);
    }

    public function test_content_list_handler_schema(): void
    {
        $schema = ContentListHandler::schema();

        $this->assertEquals('content_list', $schema['name']);
        $this->assertArrayHasKey('workspace', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('type', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('status', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('search', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('limit', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('offset', $schema['inputSchema']['properties']);
        $this->assertContains('workspace', $schema['inputSchema']['required']);
    }

    public function test_content_read_handler_schema(): void
    {
        $schema = ContentReadHandler::schema();

        $this->assertEquals('content_read', $schema['name']);
        $this->assertArrayHasKey('workspace', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('identifier', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('format', $schema['inputSchema']['properties']);
        $this->assertContains('workspace', $schema['inputSchema']['required']);
        $this->assertContains('identifier', $schema['inputSchema']['required']);
    }

    public function test_content_search_handler_schema(): void
    {
        $schema = ContentSearchHandler::schema();

        $this->assertEquals('content_search', $schema['name']);
        $this->assertArrayHasKey('workspace', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('query', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('type', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('category', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('tag', $schema['inputSchema']['properties']);
        $this->assertContains('workspace', $schema['inputSchema']['required']);
        $this->assertContains('query', $schema['inputSchema']['required']);
    }

    public function test_content_create_handler_schema(): void
    {
        $schema = ContentCreateHandler::schema();

        $this->assertEquals('content_create', $schema['name']);
        $this->assertArrayHasKey('workspace', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('title', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('type', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('status', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('content', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('categories', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('tags', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('seo_meta', $schema['inputSchema']['properties']);
        $this->assertContains('workspace', $schema['inputSchema']['required']);
        $this->assertContains('title', $schema['inputSchema']['required']);
    }

    public function test_content_update_handler_schema(): void
    {
        $schema = ContentUpdateHandler::schema();

        $this->assertEquals('content_update', $schema['name']);
        $this->assertArrayHasKey('workspace', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('identifier', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('title', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('change_summary', $schema['inputSchema']['properties']);
        $this->assertContains('workspace', $schema['inputSchema']['required']);
        $this->assertContains('identifier', $schema['inputSchema']['required']);
    }

    public function test_content_delete_handler_schema(): void
    {
        $schema = ContentDeleteHandler::schema();

        $this->assertEquals('content_delete', $schema['name']);
        $this->assertArrayHasKey('workspace', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('identifier', $schema['inputSchema']['properties']);
        $this->assertContains('workspace', $schema['inputSchema']['required']);
        $this->assertContains('identifier', $schema['inputSchema']['required']);
    }

    public function test_content_taxonomies_handler_schema(): void
    {
        $schema = ContentTaxonomiesHandler::schema();

        $this->assertEquals('content_taxonomies', $schema['name']);
        $this->assertArrayHasKey('workspace', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('type', $schema['inputSchema']['properties']);
        $this->assertContains('workspace', $schema['inputSchema']['required']);
    }

    /**
     * Data provider for handler classes.
     */
    public static function handlerClassProvider(): array
    {
        return [
            'ContentListHandler' => [ContentListHandler::class],
            'ContentReadHandler' => [ContentReadHandler::class],
            'ContentSearchHandler' => [ContentSearchHandler::class],
            'ContentCreateHandler' => [ContentCreateHandler::class],
            'ContentUpdateHandler' => [ContentUpdateHandler::class],
            'ContentDeleteHandler' => [ContentDeleteHandler::class],
            'ContentTaxonomiesHandler' => [ContentTaxonomiesHandler::class],
        ];
    }
}
