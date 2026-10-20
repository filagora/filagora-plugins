<?php

declare(strict_types=1);

use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Plugins\FilagoraPluginsPlugin;
use Filament\Panel;

it('merges the package config', function () {
    expect(config('filagora-plugins.storage.disk'))->toBe('local');
});

it('registers on a panel', function () {
    $panel = Panel::make()->id('admin')->plugin(FilagoraPluginsPlugin::make());

    expect($panel->hasPlugin('filagora-plugins'))->toBeTrue();
});

it('registers itself as a module that needs core', function () {
    $module = app(ModuleRegistry::class)->get('plugins');

    expect($module->requires)->toBe(['core']);
});
