<?php

declare(strict_types=1);

namespace Hemp\NovaMcp;

use Illuminate\Http\Request;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;
use Laravel\Nova\Tool;

class NovaMcp extends Tool
{
    /**
     * Perform any tasks that need to happen when the tool is booted.
     */
    public function boot(): void
    {
        $manifest = __DIR__.'/../dist/mix-manifest.json';

        if (file_exists($manifest)) {
            Nova::mix('nova-mcp', $manifest);
        }
    }

    /**
     * Build the menu that renders the navigation links for the tool.
     */
    public function menu(Request $request): MenuSection
    {
        return MenuSection::make('Nova MCP')
            ->path('/nova-mcp')
            ->icon('server');
    }
}
