<?php

namespace Hemp\NovaMcp\Tests\Fixtures\Nova;

use Hemp\NovaMcp\Tests\Fixtures\Models\Tag;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;

class TagResource extends Resource
{
    public static $model = Tag::class;

    public static $title = 'name';

    public static $search = ['id', 'name'];

    public static function uriKey(): string
    {
        return 'tags';
    }

    public static function label(): string
    {
        return 'Tags';
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Name')->rules('required', 'max:255'),
        ];
    }
}
