<?php

declare(strict_types=1);

namespace Hemp\NovaMcp\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Nova\Http\Requests\DeleteResourceRequest;
use Laravel\Nova\Http\Requests\DeletionRequest;
use Laravel\Nova\Jobs\DeleteResources;

#[IsDestructive]
class DeleteRecordsTool extends DeletionTool
{
    protected static function suffix(): string
    {
        return 'delete';
    }

    public function title(): string
    {
        return sprintf('Delete %s', $this->label());
    }

    public function description(): string
    {
        return sprintf(
            'Delete %s records through Nova, honoring the resource\'s delete policy, field deletion callbacks and action events. Soft deleting resources are trashed rather than removed.',
            $this->label(),
        );
    }

    protected function requestClass(): string
    {
        return DeleteResourceRequest::class;
    }

    protected function perform(DeletionRequest $request): void
    {
        DeleteResources::dispatchSync($request, $this->resourceClass);
    }

    protected function verb(): string
    {
        return 'deleted';
    }
}
