<?php

namespace Hemp\NovaMcp\Tests\Fixtures\Factories;

use Hemp\NovaMcp\Tests\Fixtures\Models\Article;
use Hemp\NovaMcp\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'user_id' => User::factory(),
            'blocks' => null,
        ];
    }
}
