<?php

declare(strict_types=1);

namespace Core\Mod\Content\Database\Factories;

use Core\Mod\Content\Models\ContentWebhookEndpoint;
use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContentWebhookEndpointFactory extends Factory
{
    protected $model = ContentWebhookEndpoint::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => $this->faker->company(),
            'secret' => $this->faker->sha256(),
            'require_signature' => true,
            'allowed_types' => [],
            'is_enabled' => true,
            'failure_count' => 0,
        ];
    }

    public function circuitBroken(): static
    {
        return $this->state([
            'failure_count' => ContentWebhookEndpoint::MAX_FAILURES,
            'is_enabled' => false,
        ]);
    }

    public function disabled(): static
    {
        return $this->state([
            'is_enabled' => false,
        ]);
    }
}
