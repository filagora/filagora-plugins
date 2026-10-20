<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Actions;

use Filagora\Plugins\Enums\ModerationAction;
use Filagora\Plugins\Enums\PluginReportStatus;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Exceptions\ModerationRefused;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Models\PluginReport;
use Filagora\Plugins\Services\Moderation;
use Filagora\Plugins\Services\Reports;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The moderation steps as table actions. Each asks for a note, hands the work to the service (which
 * checks the person may moderate) and says what happened.
 */
final class ModerationActions
{
    public static function approvePlugin(): Action
    {
        return self::decision('approve', ModerationAction::APPROVE, 'heroicon-o-check-circle', 'success', false)
            ->visible(fn (Plugin $record): bool => $record->status === PluginStatus::MODERATED)
            ->action(fn (Plugin $record, array $data) => self::run(fn (Authenticatable $moderator) => app(Moderation::class)->moderatePlugin($moderator, $record, ModerationAction::APPROVE, $data['note'] ?? null)));
    }

    public static function rejectPlugin(): Action
    {
        return self::decision('reject', ModerationAction::REJECT_PERMANENTLY, 'heroicon-o-x-circle', 'danger', true)
            ->visible(fn (Plugin $record): bool => $record->status === PluginStatus::MODERATED)
            ->action(fn (Plugin $record, array $data) => self::run(fn (Authenticatable $moderator) => app(Moderation::class)->moderatePlugin($moderator, $record, ModerationAction::REJECT_PERMANENTLY, $data['note'] ?? null)));
    }

    public static function approveRelease(): Action
    {
        return self::decision('approve', ModerationAction::APPROVE, 'heroicon-o-check-circle', 'success', false)
            ->visible(fn (PluginRelease $record): bool => $record->status === PluginStatus::MODERATED)
            ->action(fn (PluginRelease $record, array $data) => self::run(fn (Authenticatable $moderator) => app(Moderation::class)->moderateRelease($moderator, $record->plugin, $record, ModerationAction::APPROVE, $data['note'] ?? null)));
    }

    public static function rejectRelease(): Action
    {
        return self::decision('reject', ModerationAction::REJECT_PERMANENTLY, 'heroicon-o-x-circle', 'danger', true)
            ->visible(fn (PluginRelease $record): bool => $record->status === PluginStatus::MODERATED)
            ->action(fn (PluginRelease $record, array $data) => self::run(fn (Authenticatable $moderator) => app(Moderation::class)->moderateRelease($moderator, $record->plugin, $record, ModerationAction::REJECT_PERMANENTLY, $data['note'] ?? null)));
    }

    public static function reviewReport(PluginReportStatus $status): Action
    {
        return Action::make($status->value)
            ->label($status === PluginReportStatus::DISMISSED ? __('filagora-plugins::messages.reports.dismiss') : __('filagora-plugins::messages.reports.mark_reviewed'))
            ->icon($status === PluginReportStatus::DISMISSED ? 'heroicon-o-x-mark' : 'heroicon-o-check')
            ->color($status === PluginReportStatus::DISMISSED ? 'gray' : 'success')
            ->visible(fn (PluginReport $record): bool => $record->status === PluginReportStatus::OPEN)
            ->schema([Textarea::make('note')->label(__('filagora-plugins::messages.note'))->rows(3)->maxLength(4000)])
            ->action(fn (PluginReport $record, array $data) => self::run(fn (Authenticatable $moderator) => app(Reports::class)->review($moderator, $record, $status, $data['note'] ?? null)));
    }

    private static function decision(string $name, ModerationAction $action, string $icon, string $color, bool $noteRequired): Action
    {
        return Action::make($name)
            ->label(__("filagora-plugins::messages.moderation.{$name}"))
            ->icon($icon)
            ->color($color)
            ->schema([Textarea::make('note')->label(__('filagora-plugins::messages.note'))->rows(3)->required($noteRequired)->maxLength(4000)]);
    }

    /**
     * @param  \Closure(Authenticatable): mixed  $work
     */
    private static function run(\Closure $work): void
    {
        $user = auth()->user();

        if (! $user instanceof Authenticatable) {
            return;
        }

        try {
            $work($user);
        } catch (ModerationRefused $refused) {
            Notification::make()->title($refused->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title(__('filagora-plugins::messages.done'))->success()->send();
    }
}
