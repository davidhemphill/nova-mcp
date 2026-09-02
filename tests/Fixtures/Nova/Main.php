<?php

namespace Hemp\NovaMcp\Tests\Fixtures\Nova;

use Laravel\Nova\Cards\Help;
use Laravel\Nova\Dashboards\Main as Dashboard;

class Main extends Dashboard
{
    public function cards(): array
    {
        return [new Help];
    }
}
