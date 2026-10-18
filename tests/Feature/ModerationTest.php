<?php

declare(strict_types=1);

use Filagora\Plugins\Enums\ModerationAction;
use Filagora\Plugins\Enums\ModerationRefusal;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Events\PluginModerated;
use Filagora\Plugins\Events\ReleasePublished;
use Filagora\Plugins\Events\ReleaseRejected;
use Filagora\Plugins\Exceptions\ModerationRefused;
use Filagora\Plugins\Models\PluginModeratorNote;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Services\Moderation;
use Filagora\Plugins\Tests\Support\TestUser;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([PluginModerated::class, ReleasePublished::class, ReleaseRejected::class]);
});

function moderationRefusal(Closure $work): ?ModerationRefusal
{
    try {
        $work();
    } catch (ModerationRefused $e) {
        return $e->reason;
    }

    return null;
}

it('approves a plugin, leaves a note, and says so', function () {
    $mo = moderator();
    $plugin = livePlugin(TestUser::make('Ada'), PluginStatus::MODERATED);

    app(Moderation::class)->moderatePlugin($mo, $plugin, ModerationAction::APPROVE, '  Looks good  ');

    expect($plugin->fresh()->status)->toBe(PluginStatus::ACTIVE)
        ->and(PluginModeratorNote::first()->note)->toBe("Plugin approved.\n\nLooks good")
        ->and(PluginModeratorNote::first()->moderator_id)->toBe($mo->id);
    Event::assertDispatched(PluginModerated::class, fn (PluginModerated $e): bool => $e->action === ModerationAction::APPROVE && $e->note === 'Looks good');
});

it('rejects a plugin for good only with a note', function () {
    $plugin = livePlugin(TestUser::make('Ada'), PluginStatus::MODERATED);

    expect(moderationRefusal(fn () => app(Moderation::class)->moderatePlugin(moderator(), $plugin, ModerationAction::REJECT_PERMANENTLY, '  ')))->toBe(ModerationRefusal::NOTE_REQUIRED)
        ->and($plugin->fresh()->status)->toBe(PluginStatus::MODERATED);

    app(Moderation::class)->moderatePlugin(moderator('Mo2'), $plugin, ModerationAction::REJECT_PERMANENTLY, 'Copied code');

    expect($plugin->fresh()->status)->toBe(PluginStatus::REJECTED_FINAL);
});

it('refuses anyone who may not moderate, and changes nothing', function () {
    $plugin = livePlugin(null, PluginStatus::MODERATED);
    $release = $plugin->releases->first();
    $someone = TestUser::make('Nobody');

    expect(moderationRefusal(fn () => app(Moderation::class)->moderatePlugin($someone, $plugin, ModerationAction::APPROVE)))->toBe(ModerationRefusal::NOT_PERMITTED)
        ->and(moderationRefusal(fn () => app(Moderation::class)->moderateRelease($someone, $plugin, $release, ModerationAction::APPROVE)))->toBe(ModerationRefusal::NOT_PERMITTED)
        ->and($plugin->fresh()->status)->toBe(PluginStatus::MODERATED)
        ->and(PluginModeratorNote::count())->toBe(0);
    Event::assertNothingDispatched();
});

it('approves a release, publishes it, and approves a plugin still waiting on its first release', function () {
    $author = TestUser::make('Ada');
    $mo = moderator();
    $plugin = livePlugin($author, PluginStatus::MODERATED);
    $release = $plugin->releases->first();

    app(Moderation::class)->moderateRelease($mo, $plugin, $release, ModerationAction::APPROVE);

    expect($release->fresh()->status)->toBe(PluginStatus::ACTIVE)
        ->and($release->fresh()->approved_at)->not->toBeNull()
        ->and($plugin->fresh()->status)->toBe(PluginStatus::ACTIVE)
        ->and(PluginModeratorNote::pluck('note')->all())->toBe(['Plugin approved.', 'Release v1.0.0 approved.']);
    Event::assertDispatched(ReleasePublished::class, fn (ReleasePublished $e): bool => $e->moderator->is($mo) && $e->author->is($author));
});

it('leaves a plugin the moderator already rejected alone when one of its releases is approved', function () {
    $plugin = livePlugin(TestUser::make('Ada'), PluginStatus::REJECTED_FINAL);
    $release = $plugin->releases->first();
    $release->update(['status' => PluginStatus::MODERATED]);

    app(Moderation::class)->moderateRelease(moderator(), $plugin, $release, ModerationAction::APPROVE);

    expect($release->fresh()->status)->toBe(PluginStatus::ACTIVE)
        ->and($plugin->fresh()->status)->toBe(PluginStatus::REJECTED_FINAL);
});

it('does not approve the plugin when a later release is approved', function () {
    $plugin = livePlugin(TestUser::make('Ada'), PluginStatus::ACTIVE);
    $second = PluginRelease::create(['plugin_id' => $plugin->id, 'version' => 'v1.1.0', 'zip_path' => 'x', 'status' => PluginStatus::MODERATED]);

    app(Moderation::class)->moderateRelease(moderator(), $plugin, $second, ModerationAction::APPROVE);

    expect(PluginModeratorNote::count())->toBe(1);
});

it('rejects a release for good with a note and says so', function () {
    $plugin = livePlugin(TestUser::make('Ada'), PluginStatus::MODERATED);
    $release = $plugin->releases->first();

    expect(moderationRefusal(fn () => app(Moderation::class)->moderateRelease(moderator(), $plugin, $release, ModerationAction::REJECT_PERMANENTLY)))->toBe(ModerationRefusal::NOTE_REQUIRED);

    app(Moderation::class)->moderateRelease(moderator('Mo2'), $plugin, $release, ModerationAction::REJECT_PERMANENTLY, 'Broken');

    expect($release->fresh()->status)->toBe(PluginStatus::REJECTED_FINAL)->and($release->fresh()->approved_at)->toBeNull();
    Event::assertDispatched(ReleaseRejected::class, fn (ReleaseRejected $e): bool => $e->note === 'Broken' && ! $e->superseded);
    Event::assertNotDispatched(ReleasePublished::class);
});

it('refuses a release that belongs to another plugin', function () {
    $plugin = livePlugin(null, PluginStatus::MODERATED);
    $other = livePlugin(null, PluginStatus::MODERATED, 'other');

    expect(moderationRefusal(fn () => app(Moderation::class)->moderateRelease(moderator(), $plugin, $other->releases->first(), ModerationAction::APPROVE)))->toBe(ModerationRefusal::RELEASE_MISMATCH);
});

it('lets staff leave a plain note', function () {
    $plugin = livePlugin();
    $mo = moderator();

    app(Moderation::class)->addNote($plugin, $mo, 'Handed to Bo', 'Owner is away');

    expect(PluginModeratorNote::first()->note)->toBe("Handed to Bo\n\nOwner is away");
});
