<?php

namespace Hemp\NovaMcp\Tests\Fixtures\Nova;

use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class Rename extends Action
{
    public $name = 'Rename';

    /**
     * Perform the action on the given models.
     */
    public function handle(ActionFields $fields, Collection $models): mixed
    {
        $models->each->update(['name' => $fields->get('name')]);

        return Action::message("Renamed {$models->count()} records.");
    }

    /**
     * Get the fields available on the action.
     *
     * @return array<int, Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            Text::make('Name')->rules('required', 'max:255'),
        ];
    }
}
