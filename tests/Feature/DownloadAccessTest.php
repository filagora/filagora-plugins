<?php

declare(strict_types=1);

use Filagora\Plugins\Contracts\DownloadAccess;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginType;
use Filagora\Plugins\Tests\Support\GrantedKeys;
use Filagora\Plugins\Tests\Support\TestUser;

function downloadable(?string $scope = null, PluginStatus $plugin = PluginStatus::ACTIVE, bool $typeActive = true): Plugin
{
    $type = PluginType::create(['name' => 'T'.uniqid(), 'slug' => 'type-'.uniqid(), 'required_entitlement_scope' => $scope, 'is_active' => $typeActive]);

    return Plugin::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'plugin_type_id' => $type->id, 'status' => $plugin]);
}

it('lets anyone download an active plugin of a type with no requirement', function () {
    $plugin = downloadable();

    expect(app(DownloadAccess::class)->canDownloadPlugin(null, $plugin))->toBeTrue()
        ->and(app(DownloadAccess::class)->requiredScope($plugin))->toBe('');
});

it('holds a type with a requirement to its scope', function () {
    $plugin = downloadable('plugins.members');
    $member = TestUser::make('Mem');
    app(GrantedKeys::class)->give($member, 'plugins.members');

    expect(app(DownloadAccess::class)->requiredScope($plugin))->toBe('plugins.members')
        ->and(app(DownloadAccess::class)->canDownloadPlugin($member, $plugin))->toBeTrue()
        ->and(app(DownloadAccess::class)->canDownloadPlugin(TestUser::make('Other'), $plugin))->toBeFalse()
        ->and(app(DownloadAccess::class)->canDownloadPlugin(null, $plugin))->toBeFalse();
});

it('refuses a plugin that is not active or whose type is switched off, even to a member', function () {
    $member = TestUser::make('Mem');
    app(GrantedKeys::class)->give($member, 'plugins.members');

    expect(app(DownloadAccess::class)->canDownloadPlugin($member, downloadable(null, PluginStatus::MODERATED)))->toBeFalse()
        ->and(app(DownloadAccess::class)->canDownloadPlugin($member, downloadable(null, PluginStatus::ACTIVE, false)))->toBeFalse();
});

it('lets an active release be downloaded, and an archived one while that is allowed', function () {
    $plugin = downloadable();
    $active = addRelease($plugin, '1.0.0');
    $archived = addRelease($plugin, '0.9.0', PluginStatus::ARCHIVED);
    $access = app(DownloadAccess::class);

    expect($access->canDownloadRelease(null, $active))->toBeTrue()
        ->and($access->canDownloadRelease(null, $archived))->toBeTrue();

    config(['filagora-plugins.downloads.archived_releases' => false]);

    expect($access->canDownloadRelease(null, $archived->fresh()))->toBeFalse()
        ->and($access->canDownloadRelease(null, $active->fresh()))->toBeTrue();
});

it('refuses a release that is waiting, rejected or quarantined', function (PluginStatus $status) {
    $release = addRelease(downloadable(), '1.0.0', $status);

    expect(app(DownloadAccess::class)->canDownloadRelease(null, $release))->toBeFalse();
})->with([PluginStatus::MODERATED, PluginStatus::REJECTED_FINAL, PluginStatus::QUARANTINED]);

it('applies the type\'s requirement to its releases', function () {
    $release = addRelease(downloadable('plugins.members'), '1.0.0');
    $member = TestUser::make('Mem');
    app(GrantedKeys::class)->give($member, 'plugins.members');

    expect(app(DownloadAccess::class)->canDownloadRelease($member, $release))->toBeTrue()
        ->and(app(DownloadAccess::class)->canDownloadRelease(null, $release))->toBeFalse();
});
