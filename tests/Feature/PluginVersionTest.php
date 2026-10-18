<?php

declare(strict_types=1);

use Filagora\Plugins\Support\PluginVersion;

it('reduces whatever was typed to one canonical form', function (string $raw, ?string $canonical) {
    expect(PluginVersion::canonical($raw))->toBe($canonical);
})->with([
    ['1.0.1', 'v1.0.1'],
    ['v1.0.1', 'v1.0.1'],
    ['Ver5.6.0', 'v5.6.0'],
    ['1.0.1-r8256', 'v1.0.1'],
    ['beta', null],
]);

it('accepts two to four numeric parts and nothing else as a new version', function (string $raw, bool $valid) {
    expect(PluginVersion::isValidSubmission($raw))->toBe($valid);
})->with([
    ['1.0', true],
    ['v2.1.0', true],
    ['V1.2.3.4', true],
    [' 1.0.1 ', true],
    ['1', false],
    ['1.2.3.4.5', false],
    ['1.0-beta', false],
    ['one.two', false],
    ['', false],
]);

it('gives version_compare something to chew on, and sorts an unreadable version lowest', function () {
    expect(PluginVersion::comparable('v1.2.0'))->toBe('1.2.0')
        ->and(PluginVersion::comparable('nonsense'))->toBe('0')
        ->and(version_compare(PluginVersion::comparable('v1.10.0'), PluginVersion::comparable('v1.9.0'), '>'))->toBeTrue();
});
