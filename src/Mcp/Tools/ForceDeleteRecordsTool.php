<?php

declare(strict_types=1);

namespace Hemp\NovaMcp\Mcp\Tools;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Nova\Http\Requests\DeletionRequest;
use Laravel\Nova\Http\Requests\ForceDeleteResourceRequest;
use Laravel\Nova\Jobs\ForceDeleteResources;

#[IsDestructive]
class ForceDeleteRecordsTool extends DeletionTool
{
    protected static function suffix(): string
    {
        return 'force_delete';
    }

    public function title(): string
    {
        return sprintf('Force Delete %s', $this->label());
    }

    public function description(): string
    {
        return sprintf(
            'Permanently delete %s records through Nova, bypassing soft deletes. This cannot be undone.',
            $this->label(),
        );
    }

    protected function requestClass(): string
    {
        return ForceDeleteResourceRequest::class;
    }

    protected function perform(DeletionRequest $request): void
    {
        ForceDeleteResources::dispatchSync($request, $this->resourceClass);
    }

    /**
     * A trashed row has still not been force deleted, so include it.
     *
     * @return Builder<Model>
     */
    protected function pendingQuery(): Builder
    {
        $query = $this->resourceClass::newModel()->newQuery();

        return $this->resourceClass::softDeletes() ? $query->withTrashed() : $query;
    }

    protected function verb(): string
    {
        return 'forceDeleted';
    }
}
