<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;

/**
 * What other packages add to the staff screens for releases: columns, filters and actions on the
 * list, and relation managers on the edit page. The security package puts its scan result, its
 * re-scan and quarantine actions and the scan history there.
 *
 * Each addition is a closure that builds the component when a screen is set up, under a key of the
 * caller's own: registering the same key again replaces it, so a plugin that is registered on
 * several panels adds itself once.
 */
final class ReleaseScreens
{
    /** @var array<string, Closure(): Column> */
    private array $columns = [];

    /** @var array<string, Closure(): BaseFilter> */
    private array $filters = [];

    /** @var array<string, Closure(): (Action|ActionGroup)> */
    private array $actions = [];

    /** @var array<string, Closure(): BulkAction> */
    private array $bulkActions = [];

    /** @var array<string, class-string> */
    private array $relationManagers = [];

    /**
     * @param  Closure(): Column  $make
     */
    public function column(string $key, Closure $make): void
    {
        $this->columns[$key] = $make;
    }

    /**
     * @param  Closure(): BaseFilter  $make
     */
    public function filter(string $key, Closure $make): void
    {
        $this->filters[$key] = $make;
    }

    /**
     * An action on each row of the list.
     *
     * @param  Closure(): (Action|ActionGroup)  $make
     */
    public function action(string $key, Closure $make): void
    {
        $this->actions[$key] = $make;
    }

    /**
     * @param  Closure(): BulkAction  $make
     */
    public function bulkAction(string $key, Closure $make): void
    {
        $this->bulkActions[$key] = $make;
    }

    /**
     * @param  class-string  $manager  A Filament relation manager.
     */
    public function relationManager(string $key, string $manager): void
    {
        $this->relationManagers[$key] = $manager;
    }

    /**
     * @return list<Column>
     */
    public function columns(): array
    {
        return array_values(array_map(static fn (Closure $make): Column => $make(), $this->columns));
    }

    /**
     * @return list<BaseFilter>
     */
    public function filters(): array
    {
        return array_values(array_map(static fn (Closure $make): BaseFilter => $make(), $this->filters));
    }

    /**
     * @return list<Action|ActionGroup>
     */
    public function actions(): array
    {
        return array_values(array_map(static fn (Closure $make): Action|ActionGroup => $make(), $this->actions));
    }

    /**
     * @return list<BulkAction>
     */
    public function bulkActions(): array
    {
        return array_values(array_map(static fn (Closure $make): BulkAction => $make(), $this->bulkActions));
    }

    /**
     * @return list<class-string>
     */
    public function relationManagers(): array
    {
        return array_values($this->relationManagers);
    }
}
