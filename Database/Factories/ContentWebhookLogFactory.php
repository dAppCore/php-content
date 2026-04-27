<?php

declare(strict_types=1);

namespace Core\Mod\Content\Database\Factories;

use Core\Mod\Content\Models\ContentWebhookEndpoint;
use Core\Mod\Content\Models\ContentWebhookLog;
use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContentWebhookLogFactory extends Factory
{
    protected $model = ContentWebhookLog::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'endpoint_id' => ContentWebhookEndpoint::factory(),
            'event_type' => $this->faker->randomElement([
                'wordpress.post_created',
                'wordpress.post_updated',
                'cms.content_created',
            ]),
            'payload' => ['title' => $this->faker->sentence()],
            'status' => 'pending',
            'source_ip' => $this->faker->ipv4(),
        ];
    }
}
