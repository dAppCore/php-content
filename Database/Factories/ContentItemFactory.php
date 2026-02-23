<?php

declare(strict_types=1);

namespace Core\Mod\Content\Database\Factories;

use Core\Mod\Content\Enums\ContentType;
use Core\Mod\Content\Models\ContentItem;
use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContentItemFactory extends Factory
{
    protected $model = ContentItem::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'content_type' => ContentType::NATIVE->value,
            'type' => 'post',
            'status' => 'publish',
            'slug' => $this->faker->slug(),
            'title' => $this->faker->sentence(),
            'excerpt' => $this->faker->paragraph(),
            'content_html' => '<p>'.$this->faker->paragraphs(3, true).'</p>',
        ];
    }
}
