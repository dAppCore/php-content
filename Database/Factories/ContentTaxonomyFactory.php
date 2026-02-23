<?php

declare(strict_types=1);

namespace Core\Mod\Content\Database\Factories;

use Core\Mod\Content\Models\ContentTaxonomy;
use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContentTaxonomyFactory extends Factory
{
    protected $model = ContentTaxonomy::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'type' => $this->faker->randomElement(['category', 'tag']),
            'name' => $this->faker->word(),
            'slug' => $this->faker->slug(),
            'count' => $this->faker->numberBetween(0, 100),
        ];
    }
}
