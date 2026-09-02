<?php

namespace Hemp\NovaMcp\Tests\Fixtures\Nova;

use Laravel\Nova\Fields\Repeater\Repeatable;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class ContentBlock extends Repeatable
{
    public function fields(NovaRequest $request): array
    {
        return [
            Text::make('Heading')->rules('required', 'max:255'),
            Textarea::make('Copy'),
        ];
    }
}
