<?php

namespace Hemp\NovaMcp\Tests\Fixtures\Nova;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\LensRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Lenses\Lens;

class RecentPeople extends Lens
{
    public $name = 'Recent People';

    /**
     * Get the query builder / paginator for the lens.
     */
    public static function query(LensRequest $request, Builder $query): Builder
    {
        return $request->withOrdering($request->withFilters(
            $query->orderByDesc('id')
        ));
    }

    /**
     * Get the fields available to the lens.
     *
     * @return array<int, Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make(),
            Text::make('Name'),
        ];
    }

    /**
     * Get the URI key for the lens.
     */
    public function uriKey(): string
    {
        return 'recent-people';
    }
}
