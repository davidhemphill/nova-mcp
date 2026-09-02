<?php

namespace Hemp\NovaMcp\Tests\Fixtures\Nova;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class NameStartsWith extends Filter
{
    public $name = 'Name Starts With';

    /**
     * Apply the filter to the given query.
     */
    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return $query->where('name', 'like', $value.'%');
    }

    /**
     * Get the filter's available options.
     *
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return ['A' => 'A', 'G' => 'G'];
    }
}
