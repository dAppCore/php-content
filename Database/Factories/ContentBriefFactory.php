<?php

declare(strict_types=1);

namespace Core\Mod\Content\Database\Factories;

use Core\Mod\Content\Models\ContentBrief;
use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContentBriefFactory extends Factory
{
    protected $model = ContentBrief::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'title' => $this->faker->sentence(),
            'description' => $this->faker->paragraph(),
            'status' => ContentBrief::STATUS_PENDING,
        ];
    }
}
