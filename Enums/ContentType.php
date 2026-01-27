<?php

declare(strict_types=1);

namespace Core\Mod\Content\Enums;

/**
 * Content source type for ContentItem.
 *
 * Defines where content originates from:
 * - NATIVE: Created natively in Host Hub Content Editor (new default)
 * - HOSTUK: Alias for NATIVE (backwards compatibility)
 * - SATELLITE: Per-satellite service content (e.g., BioHost-specific help)
 * - WORDPRESS: Legacy synced content from WordPress (deprecated)
 */
enum ContentType: string
{
    case NATIVE = 'native';           // Created in Host Hub (new default)
    case HOSTUK = 'hostuk';           // Alias for native (backwards compat)
    case SATELLITE = 'satellite';     // Per-service content
    case WORDPRESS = 'wordpress';     // Legacy synced content (deprecated)

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::NATIVE => 'Native',
            self::HOSTUK => 'Host UK',
            self::SATELLITE => 'Satellite',
            self::WORDPRESS => 'WordPress (Legacy)',
        };
    }

    /**
     * Get Flux badge colour.
     */
    public function color(): string
    {
        return match ($this) {
            self::NATIVE => 'green',
            self::HOSTUK => 'violet',
            self::SATELLITE => 'blue',
            self::WORDPRESS => 'zinc',
        };
    }

    /**
     * Get icon name for UI.
     */
    public function icon(): string
    {
        return match ($this) {
            self::NATIVE => 'document-text',
            self::HOSTUK => 'home',
            self::SATELLITE => 'signal',
            self::WORDPRESS => 'globe-alt',
        };
    }

    /**
     * Check if this is native content (not WordPress).
     */
    public function isNative(): bool
    {
        return in_array($this, [self::NATIVE, self::HOSTUK, self::SATELLITE]);
    }

    /**
     * Check if this is legacy WordPress content.
     */
    public function isLegacy(): bool
    {
        return $this === self::WORDPRESS;
    }

    /**
     * Check if content uses Flux Editor.
     */
    public function usesFluxEditor(): bool
    {
        return $this->isNative();
    }

    /**
     * Get all native content types.
     */
    public static function nativeTypes(): array
    {
        return [self::NATIVE, self::HOSTUK, self::SATELLITE];
    }

    /**
     * Get string values for native types (for database queries).
     */
    public static function nativeTypeValues(): array
    {
        return array_map(fn ($type) => $type->value, self::nativeTypes());
    }

    /**
     * Get the default content type for new content.
     */
    public static function default(): self
    {
        return self::NATIVE;
    }

    /**
     * Create from string with fallback to default.
     */
    public static function fromString(?string $value): self
    {
        if ($value === null) {
            return self::default();
        }

        return self::tryFrom($value) ?? self::default();
    }
}
