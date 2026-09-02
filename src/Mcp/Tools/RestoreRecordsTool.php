<?php

declare(strict_types=1);

namespace Hemp\NovaMcp\Mcp\Tools;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Nova\Http\Requests\DeletionRequest;
use Laravel\Nova\Http\Requests\RestoreResourceRequest;
use Laravel\Nova\Jobs\RestoreResources;

class RestoreRecordsTool extends DeletionTool
{
    protected static function suffix(): string
    {
        return 'restore';
    }

    public function title(): string
    {
        return sprintf('Restore %s', $this->label());
    }

    public function description(): string
    {
        return sprintf('Restore soft deleted %s records through Nova, honouring the resource\'s restore policy.', $this->label());
    }

    protected function requestClass(): string
    {
        return RestoreResourceRequest::class;
    }

    protected function perform(DeletionRequest $request): void
    {
        RestoreResources::dispatchSync($request, $this->resourceClass);
    }

    /**
     * Only a trashed row is awaiting restoration.
     *
     * @return Builder<Model>
     */
    protected function pendingQuery(): Builder
    {
        return $this->resourceClass::newModel()->newQuery()->onlyTrashed();
    }

    protected function verb(): string
    {
        return 'restored';
    }
}
