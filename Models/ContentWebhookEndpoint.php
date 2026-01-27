<?php

declare(strict_types=1);

namespace Core\Mod\Content\Models;

use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Webhook endpoint configuration for receiving external content webhooks.
 *
 * Each workspace can have multiple webhook endpoints configured,
 * allowing external CMS systems (WordPress, Ghost, etc.) to push
 * content updates to the Content module.
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property string $name
 * @property string|null $secret
 * @property array|null $allowed_types
 * @property bool $is_enabled
 * @property int $failure_count
 * @property \Carbon\Carbon|null $last_received_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ContentWebhookEndpoint extends Model
{
    use HasFactory;

    protected static function newFactory(): \Core\Mod\Content\Database\Factories\ContentWebhookEndpointFactory
    {
        return \Core\Mod\Content\Database\Factories\ContentWebhookEndpointFactory::new();
    }

    protected $table = 'content_webhook_endpoints';

    protected $fillable = [
        'uuid',
        'workspace_id',
        'name',
        'secret',
        'previous_secret',
        'secret_rotated_at',
        'grace_period_seconds',
        'allowed_types',
        'is_enabled',
        'failure_count',
        'last_received_at',
    ];

    protected $casts = [
        'allowed_types' => 'array',
        'is_enabled' => 'boolean',
        'failure_count' => 'integer',
        'last_received_at' => 'datetime',
        'secret' => 'encrypted',
        'previous_secret' => 'encrypted',
        'secret_rotated_at' => 'datetime',
        'grace_period_seconds' => 'integer',
    ];

    protected $hidden = [
        'secret',
        'previous_secret',
    ];

    /**
     * Supported webhook event types.
     */
    public const ALLOWED_TYPES = [
        // WordPress events
        'wordpress.post_created',
        'wordpress.post_updated',
        'wordpress.post_deleted',
        'wordpress.post_published',
        'wordpress.post_trashed',
        'wordpress.media_uploaded',

        // Generic CMS events
        'cms.content_created',
        'cms.content_updated',
        'cms.content_deleted',
        'cms.content_published',

        // Generic payload (custom integrations)
        'generic.payload',
    ];

    /**
     * Maximum consecutive failures before auto-disable.
     */
    public const MAX_FAILURES = 10;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ContentWebhookEndpoint $endpoint) {
            if (empty($endpoint->uuid)) {
                $endpoint->uuid = (string) Str::uuid();
            }

            // Generate a secret if not provided
            if (empty($endpoint->secret)) {
                $endpoint->secret = Str::random(64);
            }

            // Default to all allowed types if not specified
            if (empty($endpoint->allowed_types)) {
                $endpoint->allowed_types = self::ALLOWED_TYPES;
            }
        });
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ContentWebhookLog::class, 'endpoint_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    // -------------------------------------------------------------------------
    // State Checks
    // -------------------------------------------------------------------------

    public function isEnabled(): bool
    {
        return $this->is_enabled === true;
    }

    public function isTypeAllowed(string $type): bool
    {
        // Allow all if no restrictions
        if (empty($this->allowed_types)) {
            return true;
        }

        // Check exact match
        if (in_array($type, $this->allowed_types, true)) {
            return true;
        }

        // Check prefix match (e.g., 'wordpress.*' matches 'wordpress.post_created')
        foreach ($this->allowed_types as $allowedType) {
            if (str_ends_with($allowedType, '.*')) {
                $prefix = substr($allowedType, 0, -1);
                if (str_starts_with($type, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isCircuitBroken(): bool
    {
        return $this->failure_count >= self::MAX_FAILURES;
    }

    // -------------------------------------------------------------------------
    // Signature Verification
    // -------------------------------------------------------------------------

    /**
     * Verify webhook signature.
     *
     * Supports multiple signature formats:
     * - X-Signature: HMAC-SHA256 signature of the raw body
     * - X-Hub-Signature-256: GitHub-style sha256=... format
     * - X-WP-Webhook-Signature: WordPress webhook signature
     *
     * During a grace period after secret rotation, both current and
     * previous secrets are accepted to avoid breaking integrations.
     */
    public function verifySignature(string $payload, ?string $signature): bool
    {
        // If no secret configured, skip verification (but log warning)
        if (empty($this->secret)) {
            return true;
        }

        // Signature required when secret is set
        if (empty($signature)) {
            return false;
        }

        // Normalise signature (handle sha256=... format)
        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }

        // Check against current secret
        $expectedSignature = hash_hmac('sha256', $payload, $this->secret);
        if (hash_equals($expectedSignature, $signature)) {
            return true;
        }

        // Check against previous secret if in grace period
        if ($this->isInGracePeriod() && ! empty($this->previous_secret)) {
            $previousExpectedSignature = hash_hmac('sha256', $payload, $this->previous_secret);
            if (hash_equals($previousExpectedSignature, $signature)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Status Management
    // -------------------------------------------------------------------------

    public function incrementFailureCount(): void
    {
        $this->increment('failure_count');

        // Auto-disable after too many failures (circuit breaker)
        if ($this->failure_count >= self::MAX_FAILURES) {
            $this->update(['is_enabled' => false]);
        }
    }

    public function resetFailureCount(): void
    {
        $this->update([
            'failure_count' => 0,
            'last_received_at' => now(),
        ]);
    }

    public function markReceived(): void
    {
        $this->update(['last_received_at' => now()]);
    }

    // -------------------------------------------------------------------------
    // URL Generation
    // -------------------------------------------------------------------------

    /**
     * Get the webhook endpoint URL.
     */
    public function getEndpointUrl(): string
    {
        return route('api.content.webhooks.receive', ['endpoint' => $this->uuid]);
    }

    /**
     * Regenerate the secret and return the new value.
     */
    public function regenerateSecret(): string
    {
        $newSecret = Str::random(64);
        $this->update(['secret' => $newSecret]);

        return $newSecret;
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Get Flux badge colour for enabled status.
     */
    public function getStatusColorAttribute(): string
    {
        if (! $this->is_enabled) {
            return 'zinc';
        }

        if ($this->isCircuitBroken()) {
            return 'red';
        }

        if ($this->failure_count > 0) {
            return 'yellow';
        }

        return 'green';
    }

    /**
     * Get status label.
     */
    public function getStatusLabelAttribute(): string
    {
        if (! $this->is_enabled) {
            return 'Disabled';
        }

        if ($this->isCircuitBroken()) {
            return 'Circuit Open';
        }

        if ($this->failure_count > 0) {
            return "Active ({$this->failure_count} failures)";
        }

        return 'Active';
    }

    /**
     * Get icon for status.
     */
    public function getStatusIconAttribute(): string
    {
        if (! $this->is_enabled) {
            return 'pause-circle';
        }

        if ($this->isCircuitBroken()) {
            return 'exclamation-triangle';
        }

        return 'check-circle';
    }

    // -------------------------------------------------------------------------
    // Secret Rotation Methods
    // -------------------------------------------------------------------------

    /**
     * Check if the webhook is currently in a grace period.
     */
    public function isInGracePeriod(): bool
    {
        if (empty($this->secret_rotated_at)) {
            return false;
        }

        $rotatedAt = Carbon::parse($this->secret_rotated_at);
        $gracePeriodSeconds = $this->grace_period_seconds ?? 86400;
        $graceEndsAt = $rotatedAt->copy()->addSeconds($gracePeriodSeconds);

        return now()->isBefore($graceEndsAt);
    }

    /**
     * Get the time remaining in the grace period.
     */
    public function getGraceTimeRemainingAttribute(): ?int
    {
        if (! $this->isInGracePeriod()) {
            return null;
        }

        $rotatedAt = Carbon::parse($this->secret_rotated_at);
        $gracePeriodSeconds = $this->grace_period_seconds ?? 86400;
        $graceEndsAt = $rotatedAt->copy()->addSeconds($gracePeriodSeconds);

        return (int) now()->diffInSeconds($graceEndsAt, false);
    }

    /**
     * Get when the grace period ends.
     */
    public function getGraceEndsAtAttribute(): ?Carbon
    {
        if (empty($this->secret_rotated_at)) {
            return null;
        }

        $rotatedAt = Carbon::parse($this->secret_rotated_at);
        $gracePeriodSeconds = $this->grace_period_seconds ?? 86400;

        return $rotatedAt->copy()->addSeconds($gracePeriodSeconds);
    }
}
