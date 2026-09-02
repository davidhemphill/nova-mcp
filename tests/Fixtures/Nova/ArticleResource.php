<?php

namespace Hemp\NovaMcp\Tests\Fixtures\Nova;

use Hemp\NovaMcp\Tests\Fixtures\Models\Article;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Repeater;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;

/**
 * Exercises the field shapes that hold no value of their own: a Repeater and
 * a to-many relationship.
 */
class ArticleResource extends Resource
{
    public static $model = Article::class;

    public static $title = 'title';

    public static $search = ['id', 'title'];

    public static function uriKey(): string
    {
        return 'articles';
    }

    public static function label(): string
    {
        return 'Articles';
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Title')->rules('required', 'max:255'),

            BelongsTo::make('User', 'user', UserResource::class),

            Repeater::make('Blocks')
                ->repeatables([ContentBlock::make()])
                ->asJson(),

            BelongsToMany::make('Tags', 'tags', TagResource::class),
        ];
    }
}
