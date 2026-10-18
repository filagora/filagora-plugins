<?php

declare(strict_types=1);

use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Schema\TableShape;

/*
 * The database may already have been built, without forums, by an earlier test, so these tests bring
 * the forums tables and the link up to date themselves and take the link off again afterwards.
 */
beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
    runThreadLinkMigration('up');
});

afterEach(function () {
    runThreadLinkMigration('down');
});

function runThreadLinkMigration(string $direction): void
{
    $migration = require __DIR__.'/../../database/migrations/forums/2026_10_19_100000_add_thread_foreign_key_to_plugins_table.php';
    $migration->{$direction}();
}

it('ties a plugin to its support thread when forums is installed', function () {
    expect(TableShape::inspect('plugins')->foreignKeys)->toHaveKey('plugins_forum_thread_id_foreign')
        ->and(TableShape::inspect('plugins')->foreignKeys['plugins_forum_thread_id_foreign']['on_delete'])->toBe('set null');
});

it('matches the shipped extension, so a site with forums adopts the table', function () {
    $schema = app(SchemaRegistry::class)->get('plugins')->extendedBy(app(SchemaRegistry::class)->extensionsFor('plugins'));

    expect(TableShape::inspect('plugins')->differencesFrom($schema->tables['plugins']))->toBe([]);
});

it('asks a site with forums for the link when it adopts, and a site without forums does not', function () {
    expect(app(SchemaRegistry::class)->extensionsFor('plugins'))->toHaveCount(1);
});

it('can be run again and taken off again', function () {
    runThreadLinkMigration('up');
    expect(TableShape::inspect('plugins')->foreignKeys)->toHaveKey('plugins_forum_thread_id_foreign');

    runThreadLinkMigration('down');
    expect(TableShape::inspect('plugins')->foreignKeys)->not->toHaveKey('plugins_forum_thread_id_foreign');

    runThreadLinkMigration('down');
    runThreadLinkMigration('up');
    expect(TableShape::inspect('plugins')->foreignKeys)->toHaveKey('plugins_forum_thread_id_foreign');
});
