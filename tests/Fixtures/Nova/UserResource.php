<?php

namespace Hemp\NovaMcp\Tests\Fixtures\Nova;

use Hemp\NovaMcp\Tests\Fixtures\Models\User;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Password;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;

/**
 * Mirrors the stock Nova user resource, so the suite exercises the same
 * fields and validation rules a fresh install ships with.
 */
class UserResource extends Resource
{
    public static $model = User::class;

    public static $title = 'name';

    public static $search = ['id', 'name', 'email'];

    public static function uriKey(): string
    {
        return 'users';
    }

    public static function label(): string
    {
        return 'Users';
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Name')
                ->sortable()
                ->rules('required', 'max:255'),

            Text::make('Email')
                ->sortable()
                ->rules('required', 'email', 'max:254')
                ->creationRules('unique:users,email')
                ->updateRules('unique:users,email,{{resourceId}}'),

            Password::make('Password')
                ->onlyOnForms()
                ->creationRules('required', 'string', 'min:8')
                ->updateRules('nullable', 'string', 'min:8'),
        ];
    }
}
