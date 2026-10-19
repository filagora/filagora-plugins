<?php

declare(strict_types=1);

use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Tests\Support\TestUser;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-19 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('shows what it would do and changes nothing unless told to apply', function () {
    $plugin = archivable('widget', ['1.0.0' => 60, '2.0.0' => 2]);

    $this->artisan('filagora:plugins:archive-releases')
        ->expectsOutputToContain('widget')
        ->expectsOutputToContain('keep')
        ->expectsOutputToContain('Would archive 1 of 2 active releases across 1 plugins')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    $this->artisan('filagora:plugins:archive-releases', ['--dry-run' => true, '--apply' => true])->expectsOutputToContain('Dry run')->assertSuccessful();

    expect(versionsWhere($plugin, PluginStatus::ACTIVE))->toBe(['1.0.0', '2.0.0']);
});

it('archives with --apply, and says who with --actor', function () {
    $admin = TestUser::make('Admin');
    $plugin = archivable('widget', ['1.0.0' => 60, '2.0.0' => 2]);

    $this->artisan('filagora:plugins:archive-releases', ['--apply' => true, '--actor' => (string) $admin->id])
        ->expectsOutputToContain('Archiving 1 of 2')
        ->expectsOutputToContain('Archived 1 release(s).')
        ->assertSuccessful();

    expect(versionsWhere($plugin, PluginStatus::ARCHIVED))->toBe(['1.0.0'])
        ->and(PluginRelease::where('status', 'archived')->first()->archived_by_id)->toBe($admin->id);
});

it('finds the actor by email, and carries on without one it cannot find', function () {
    $admin = TestUser::make('Admin');
    archivable('widget', ['1.0.0' => 60, '2.0.0' => 2]);

    $this->artisan('filagora:plugins:archive-releases', ['--apply' => true, '--actor' => $admin->email])->assertSuccessful();

    expect(PluginRelease::where('status', 'archived')->first()->archived_by_id)->toBe($admin->id);

    PluginRelease::query()->update(['status' => PluginStatus::ACTIVE]);
    $this->artisan('filagora:plugins:archive-releases', ['--apply' => true, '--actor' => 'nobody@example.test'])->assertSuccessful();

    expect(PluginRelease::where('status', 'archived')->first()->archived_by_id)->toBeNull();
});

it('says when there is nothing to archive', function () {
    archivable('widget', ['1.0.0' => 2]);

    $this->artisan('filagora:plugins:archive-releases', ['--apply' => true])->expectsOutputToContain('Nothing to archive.')->assertSuccessful();
});

it('can be limited to plugins by slug or id, and to a number of releases', function () {
    $first = archivable('first', ['1.0.0' => 60, '1.1.0' => 50, '2.0.0' => 2]);
    $second = archivable('second', ['1.0.0' => 60, '2.0.0' => 2]);

    $this->artisan('filagora:plugins:archive-releases', ['--apply' => true, '--plugin' => ['second']])->assertSuccessful();

    expect(versionsWhere($second, PluginStatus::ARCHIVED))->toBe(['1.0.0'])
        ->and(versionsWhere($first, PluginStatus::ARCHIVED))->toBe([]);

    $this->artisan('filagora:plugins:archive-releases', ['--apply' => true, '--plugin' => [(string) $first->id], '--limit' => '1'])
        ->expectsOutputToContain('capped by --limit')
        ->assertSuccessful();

    expect(versionsWhere($first, PluginStatus::ARCHIVED))->toHaveCount(1);

    $this->artisan('filagora:plugins:archive-releases', ['--apply' => true, '--plugin' => ['no-such-plugin']])->expectsOutputToContain('Nothing to archive.')->assertSuccessful();
});

it('takes the months and the version-line and dead-plugin switches from the options', function () {
    $compatibility = fakeCompatibility();
    $plugin = archivable('widget', ['1.0.0' => 60, '2.0.0' => 30, '3.0.0' => 2]);
    $compatibility->declare($plugin->releases->firstWhere('version', '1.0.0'), '1.5.7');
    $compatibility->declare($plugin->releases->firstWhere('version', '3.0.0'), '2.0.0');
    $dead = archivable('dead', ['1.0.0' => 5, '2.0.0' => 3], ['status' => PluginStatus::REJECTED_FINAL]);

    $this->artisan('filagora:plugins:archive-releases', ['--apply' => true, '--keep-months' => '12', '--no-keep-per-line' => true, '--no-whole-plugin' => true])->assertSuccessful();

    expect(versionsWhere($plugin, PluginStatus::ARCHIVED))->toBe(['1.0.0', '2.0.0'])
        ->and(versionsWhere($dead, PluginStatus::ARCHIVED))->toBe([]);

    $this->artisan('filagora:plugins:archive-releases', ['--apply' => true])->assertSuccessful();

    expect(versionsWhere($dead, PluginStatus::ARCHIVED))->toBe(['1.0.0']);
});

it('puts releases back with --restore --apply, and only shows them without --apply', function () {
    $first = archivable('first', ['1.0.0' => 60, '2.0.0' => 2]);
    $second = archivable('second', ['1.0.0' => 60, '2.0.0' => 2]);
    $this->artisan('filagora:plugins:archive-releases', ['--apply' => true])->assertSuccessful();

    $this->artisan('filagora:plugins:archive-releases', ['--restore' => true])
        ->expectsOutputToContain('Would restore 2 archived release(s)')
        ->expectsOutputToContain('--restore --apply')
        ->assertSuccessful();

    expect(PluginRelease::where('status', 'archived')->count())->toBe(2);

    $this->artisan('filagora:plugins:archive-releases', ['--restore' => true, '--apply' => true, '--plugin' => ['first']])
        ->expectsOutputToContain('Restored 1 release(s)')
        ->assertSuccessful();

    expect(versionsWhere($first, PluginStatus::ARCHIVED))->toBe([])
        ->and(versionsWhere($second, PluginStatus::ARCHIVED))->toBe(['1.0.0']);

    $this->artisan('filagora:plugins:archive-releases', ['--restore' => true, '--apply' => true, '--limit' => '5'])->assertSuccessful();

    expect(PluginRelease::where('status', 'archived')->count())->toBe(0);

    $this->artisan('filagora:plugins:archive-releases', ['--restore' => true, '--apply' => true])->expectsOutputToContain('No archived releases to restore.')->assertSuccessful();
});
