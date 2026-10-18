<?php

declare(strict_types=1);

use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Services\ReleaseSelection;

it('picks the highest version, comparing numbers and not text', function () {
    $plugin = livePlugin();
    $old = $plugin->releases->first();
    $newest = addRelease($plugin, '1.10.0');
    addRelease($plugin, '1.9.0');

    expect(app(ReleaseSelection::class)->latest($plugin->releases()->get())->is($newest))->toBeTrue()
        ->and($old->version)->toBe('v1.0.0');
});

it('gives a tie to the release touched last, then the later row', function () {
    $plugin = livePlugin();
    $first = addRelease($plugin, 'v2.0');
    $second = addRelease($plugin, '2.0.0');
    PluginRelease::query()->whereKey($first->id)->update(['updated_at' => now()->subDay()]);

    expect(app(ReleaseSelection::class)->latest($plugin->releases()->whereKey([$first->id, $second->id])->get())->is($second))->toBeTrue();

    PluginRelease::query()->update(['updated_at' => now()]);

    expect(app(ReleaseSelection::class)->latest($plugin->releases()->whereKey([$first->id, $second->id])->get())->is($second))->toBeTrue();
});

it('answers nothing when there are no releases', function () {
    expect(app(ReleaseSelection::class)->latest([]))->toBeNull()
        ->and(app(ReleaseSelection::class)->latest(collect()))->toBeNull();
});

it('treats every release as compatible when nothing tracks compatibility', function () {
    $plugin = livePlugin();

    expect(app(ReleaseSelection::class)->latest($plugin->releases, '2.1.0'))->not->toBeNull()
        ->and(app(ReleaseSelection::class)->supports($plugin->releases->first(), 'v2.1.0'))->toBeTrue();
});

it('picks the newest release that works with a target when compatibility is tracked', function () {
    $compatibility = fakeCompatibility();
    $plugin = livePlugin();
    $old = $plugin->releases->first();
    $middle = addRelease($plugin, '1.5.0');
    $newest = addRelease($plugin, '2.0.0');
    $compatibility->declare($old, '1.5.7', '2.0.0');
    $compatibility->declare($middle, '1.5.7');
    $compatibility->declare($newest, '2.0.0');
    $selection = app(ReleaseSelection::class);
    $all = $plugin->releases()->get();

    expect($selection->latest($all)->is($newest))->toBeTrue()
        ->and($selection->latest($all, '1.5.7')->is($middle))->toBeTrue()
        ->and($selection->latest($all, 'v2.0.0')->is($newest))->toBeTrue()
        ->and($selection->latest($all, '9.9.9'))->toBeNull()
        ->and($selection->latest($all, ''))->not->toBeNull();
});

it('does not take two different unreadable target labels for the same one', function () {
    $compatibility = fakeCompatibility();
    $plugin = livePlugin();
    $compatibility->declare($plugin->releases->first(), 'alpha');
    $selection = app(ReleaseSelection::class);

    expect($selection->supports($plugin->releases->first(), 'alpha'))->toBeTrue()
        ->and($selection->supports($plugin->releases->first(), 'beta'))->toBeFalse()
        ->and($selection->normalizeTarget(' V1.2 '))->toBe('1.2')
        ->and($selection->normalizeTarget('  '))->toBeNull()
        ->and($selection->normalizeTarget(null))->toBeNull();
});

it('says whether an available version is newer than an installed one', function (string $installed, string $available, bool $newer) {
    expect(app(ReleaseSelection::class)->isUpdateAvailable($installed, $available))->toBe($newer);
})->with([
    ['1.0.0', '1.0.1', true],
    ['v1.9', 'v1.10', true],
    ['2.0.0', '1.9.9', false],
    ['1.0.0', 'v1.0.0', false],
    ['rubbish', '0.1', true],
]);
