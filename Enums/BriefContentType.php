<?php

declare(strict_types=1);

namespace Core\Mod\Content\Enums;

/**
 * Content type for ContentBrief.
 *
 * Defines what kind of content the brief will generate:
 * - HELP_ARTICLE: Documentation and support content
 * - BLOG_POST: Blog articles and news
 * - LANDING_PAGE: Marketing and product landing pages
 * - SOCIAL_POST: Social media content
 */
enum BriefContentType: string
{
    case HELP_ARTICLE = 'help_article';
    case BLOG_POST = 'blog_post';
    case LANDING_PAGE = 'landing_page';
    case SOCIAL_POST = 'social_post';

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::HELP_ARTICLE => 'Help Article',
            self::BLOG_POST => 'Blog Post',
            self::LANDING_PAGE => 'Landing Page',
            self::SOCIAL_POST => 'Social Post',
        };
    }

    /**
     * Get Flux badge colour.
     */
    public function color(): string
    {
        return match ($this) {
            self::HELP_ARTICLE => 'blue',
            self::BLOG_POST => 'green',
            self::LANDING_PAGE => 'violet',
            self::SOCIAL_POST => 'orange',
        };
    }

    /**
     * Get icon name for UI.
     */
    public function icon(): string
    {
        return match ($this) {
            self::HELP_ARTICLE => 'question-mark-circle',
            self::BLOG_POST => 'newspaper',
            self::LANDING_PAGE => 'document-text',
            self::SOCIAL_POST => 'share',
        };
    }

    /**
     * Get default word count target for this content type.
     */
    public function defaultWordCount(): int
    {
        return match ($this) {
            self::HELP_ARTICLE => 800,
            self::BLOG_POST => 1200,
            self::LANDING_PAGE => 500,
            self::SOCIAL_POST => 100,
        };
    }

    /**
     * Get recommended timeout in seconds for AI generation.
     */
    public function recommendedTimeout(): int
    {
        return match ($this) {
            self::HELP_ARTICLE => 180,
            self::BLOG_POST => 240,
            self::LANDING_PAGE => 300,
            self::SOCIAL_POST => 60,
        };
    }

    /**
     * Check if this type requires long-form content.
     */
    public function isLongForm(): bool
    {
        return in_array($this, [self::HELP_ARTICLE, self::BLOG_POST, self::LANDING_PAGE]);
    }

    /**
     * Get all available values as an array (for validation rules).
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Create from string with null fallback.
     */
    public static function tryFromString(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::tryFrom($value);
    }
}
