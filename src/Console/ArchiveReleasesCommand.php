<?php

declare(strict_types=1);

namespace Filagora\Plugins\Console;

use Filagora\Core\Support\UserModel;
use Filagora\Plugins\Archiving\Archiving;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Support\Models;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

class ArchiveReleasesCommand extends Command
{
    protected $signature = 'filagora:plugins:archive-releases
        {--dry-run : Print the plan and exit (the default unless --apply is given)}
        {--apply : Do it}
        {--restore : Go the other way: archived releases return to the status they had}
        {--keep-months= : Override archiving.keep_months}
        {--no-keep-per-line : Do not keep the newest build for each version line it works with}
        {--no-whole-plugin : Skip the rules for plugins that are dead weight}
        {--plugin=* : Only these plugins (ids or slugs)}
        {--limit= : Touch at most this many releases}
        {--actor= : User id or email recorded as the one who archived (archive only)}';

    protected $description = 'Move historical plugin releases to "archived" so they leave the security sweep (reversible).';

    public function handle(Archiving $archiving): int
    {
        // --apply is the single trigger for a real change, in both directions.
        $dryRun = (bool) $this->option('dry-run') || ! $this->option('apply');
        $limit = ($raw = $this->option('limit')) !== null && $raw !== '' ? max(1, (int) $raw) : null;
        $pluginIds = $this->pluginIds();

        if ($this->option('restore')) {
            return $this->restore($archiving, $pluginIds, $limit, $dryRun);
        }

        $plan = $archiving->plan([
            'pluginIds' => $pluginIds,
            'keepMonths' => ($months = $this->option('keep-months')) !== null && $months !== '' ? (int) $months : null,
            'keepPerTargetLine' => $this->option('no-keep-per-line') ? false : null,
            'wholePlugin' => ! $this->option('no-whole-plugin'),
            'limit' => $limit,
        ]);

        if ($plan->archiveIds === []) {
            $this->info('Nothing to archive.');

            return self::SUCCESS;
        }

        foreach ($plan->plugins as $pluginId => $row) {
            $this->line("<info>{$row['slug']}</info> (#{$pluginId})");

            foreach ($row['keep'] as $id => $reason) {
                $this->line("  keep    #{$id}  - {$reason}");
            }

            foreach ($row['archive'] as $id) {
                $this->line("  <comment>archive #{$id}</comment>");
            }
        }

        $totals = $plan->totals;
        $this->newLine();
        $this->info(sprintf(
            '%s %d of %d active releases across %d plugins (%d whole-plugin)%s.',
            $dryRun ? 'Would archive' : 'Archiving',
            $totals['selected'],
            $totals['active'],
            count($plan->plugins),
            $totals['whole_plugin'],
            $limit !== null && $totals['selected'] < $totals['eligible'] ? " - capped by --limit; {$totals['eligible']} eligible in total" : '',
        ));

        if ($dryRun) {
            $this->comment('Dry run - nothing changed. Run again with --apply.');

            return self::SUCCESS;
        }

        $result = $archiving->archive($plan->archiveIds, $this->actor());
        $this->info("Archived {$result['archived']} release(s)".($result['kept_back'] > 0 ? "; kept back {$result['kept_back']} (a plugin's last active release)." : '.'));

        return self::SUCCESS;
    }

    /**
     * @param  list<int>|null  $pluginIds
     */
    private function restore(Archiving $archiving, ?array $pluginIds, ?int $limit, bool $dryRun): int
    {
        $ids = Models::class('release')::query()
            ->where('status', PluginStatus::ARCHIVED->value)
            ->when($pluginIds !== null, fn (Builder $query) => $query->whereIn('plugin_id', $pluginIds))
            ->orderBy('id')
            ->when($limit !== null, fn (Builder $query) => $query->limit($limit))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            $this->info('No archived releases to restore.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('Would restore '.count($ids).' archived release(s): '.implode(', ', array_map(static fn (int $id): string => "#{$id}", $ids)));
            $this->comment('Dry run - nothing changed. Run again with --restore --apply.');

            return self::SUCCESS;
        }

        $this->info("Restored {$archiving->restore($ids)} release(s) to the status they had.");

        return self::SUCCESS;
    }

    /**
     * @return list<int>|null Null for every plugin; a list that matches nothing when none was found.
     */
    private function pluginIds(): ?array
    {
        /** @var list<string> $given */
        $given = (array) $this->option('plugin');

        if ($given === []) {
            return null;
        }

        $numeric = array_values(array_filter($given, 'is_numeric'));

        $ids = Models::class('plugin')::query()
            ->where(function (Builder $query) use ($given, $numeric): void {
                $query->whereIn('slug', $given);

                if ($numeric !== []) {
                    $query->orWhereIn('id', array_map('intval', $numeric));
                }
            })
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return $ids ?: [-1];
    }

    private function actor(): ?Authenticatable
    {
        $given = (string) ($this->option('actor') ?? '');

        if ($given === '') {
            return null;
        }

        $user = UserModel::class()::query()
            ->when(is_numeric($given), fn (Builder $query) => $query->whereKey((int) $given), fn (Builder $query) => $query->where('email', $given))
            ->first();

        return $user instanceof Authenticatable ? $user : null;
    }
}
