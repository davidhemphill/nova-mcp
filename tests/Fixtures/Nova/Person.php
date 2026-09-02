<?php

namespace Hemp\NovaMcp\Tests\Fixtures\Nova;

use Hemp\NovaMcp\Tests\Fixtures\Models\User;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Line;
use Laravel\Nova\Fields\Stack;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Lenses\Lens;
use Laravel\Nova\Resource;

/**
 * A second Nova resource over the users table, used to exercise the filter,
 * lens and action branches of the catalog.
 */
class Person extends Resource
{
    /**
     * @var class-string<User>
     */
    public static $model = User::class;

    public static $title = 'name';

    public static $search = ['name', 'email'];

    public static function uriKey(): string
    {
        return 'people';
    }

    /**
     * @return array<int, Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            // A Stack holds no value of its own; its content lives in its lines.
            Stack::make('Identity', [
                Line::make('Name')->asHeading(),
                Line::make('Email')->asSmall(),
            ])->onlyOnIndex(),

            Text::make('Name')->sortable()->rules('required', 'max:255')->hideFromIndex(),
            Text::make('Email')->rules('required', 'email'),
        ];
    }

    /**
     * @return array<int, Filter>
     */
    public function filters(NovaRequest $request): array
    {
        return [new NameStartsWith];
    }

    /**
     * @return array<int, Lens>
     */
    public function lenses(NovaRequest $request): array
    {
        return [new RecentPeople];
    }

    /**
     * @return array<int, Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [new Rename];
    }
}
