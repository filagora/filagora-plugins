<?php

declare(strict_types=1);

use Filagora\Core\Schema\Adopter;
use Filagora\Core\Schema\AdoptionStatus;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Schema\TableShape;
use Filagora\Core\Support\Tables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The tables must match the site they were extracted from, so an existing site can adopt them as
 * they are. The shipped snapshot is that shape: it was checked against the real table definitions
 * by running `filagora:adopt --dry-run` on them, which differed only by the columns a profile
 * package declares (an import id, a zip root, the archive layout) and the foreign keys into tables
 * that other packages own.
 */

const PLUGIN_TABLES = ['plugin_types', 'plugins', 'plugin_releases', 'plugin_reports', 'plugin_subscriptions', 'plugin_moderator_notes'];
const PLUGIN_MIGRATION = '2026_10_19_000000_create_filagora_plugin_tables';

function pluginMigration(): object
{
    return require __DIR__.'/../../database/migrations/'.PLUGIN_MIGRATION.'.php';
}

/**
 * The tables as an existing site has them: no prefix, migration not recorded. The prefixed set has
 * to go first, and afterEach() puts it back.
 */
function existingPluginTables(): void
{
    pluginMigration()->down();
    config(['filagora.table_prefix' => '']);
    pluginMigration()->down();
    pluginMigration()->up();
    DB::table('migrations')->where('migration', PLUGIN_MIGRATION)->delete();
}

afterEach(function () {
    foreach (array_reverse(PLUGIN_TABLES) as $table) {
        Schema::dropIfExists($table);
    }

    config(['filagora.table_prefix' => 'filagora_']);

    if (! Schema::hasTable(Tables::name('plugins'))) {
        pluginMigration()->up();
    }
});

it('ships a snapshot of the six tables that matches the migration, column order included', function () {
    $snapshot = app(SchemaRegistry::class)->get('plugins');

    expect(array_keys($snapshot->tables))->toBe(PLUGIN_TABLES)
        ->and($snapshot->migrationNames())->toBe([PLUGIN_MIGRATION]);

    foreach ($snapshot->tables as $base => $expected) {
        expect(TableShape::inspect($base)->differencesFrom($expected, compareColumnOrder: true))->toBe([], $base);
    }
});

it('keeps the uniqueness, defaults and indexes the site relies on', function () {
    $releases = TableShape::inspect('plugin_releases');
    $plugins = TableShape::inspect('plugins');

    expect($releases->indexes['plugin_releases_plugin_id_version_unique']['unique'])->toBeTrue()
        ->and($releases->indexes['plugin_releases_plugin_id_internal_version_unique']['unique'])->toBeTrue()
        ->and($releases->indexes['plugin_releases_plugin_status_created_idx']['columns'])->toBe(['plugin_id', 'status', 'created_at'])
        ->and($releases->columns['status']['default'])->toBe('moderated')
        ->and($releases->columns['download_count']['default'])->toBe('0')
        ->and($plugins->indexes['plugins_slug_unique']['unique'])->toBeTrue()
        ->and($plugins->indexes['plugins_status_type_updated_idx']['columns'])->toBe(['status', 'plugin_type_id', 'updated_at', 'created_at'])
        ->and($plugins->columns['forum_thread_id']['nullable'])->toBeTrue()
        ->and(TableShape::inspect('plugin_reports')->columns['status']['default'])->toBe('open')
        ->and(TableShape::inspect('plugin_types')->columns['is_active']['default'])->toBe('1');
});

it('leaves the links into other packages\' tables as plain columns', function () {
    $releases = TableShape::inspect('plugin_releases');

    expect($releases->foreignKeys)->not->toHaveKey('plugin_releases_latest_security_scan_id_foreign')
        ->and($releases->foreignKeys)->not->toHaveKey('plugin_releases_quarantine_scan_id_foreign')
        ->and($releases->indexes)->toHaveKeys(['plugin_releases_latest_security_scan_id_foreign', 'plugin_releases_quarantine_scan_id_foreign'])
        ->and(TableShape::inspect('plugins')->foreignKeys)->not->toHaveKey('plugins_forum_thread_id_foreign');
});

it('adopts the plugin tables of an existing site and records the migration', function () {
    existingPluginTables();

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('plugins'));

    expect($plan->status)->toBe(AdoptionStatus::Adoptable, implode('; ', $plan->differences))
        ->and($plan->pendingMigrations)->toBe([PLUGIN_MIGRATION]);

    $this->artisan('filagora:adopt', ['--package' => 'plugins'])->expectsOutputToContain('Recorded 1 migration(s) as run.')->assertExitCode(0);

    expect(DB::table('migrations')->where('migration', PLUGIN_MIGRATION)->exists())->toBeTrue();
});

it('refuses a site that lost an index or has only some of the tables', function () {
    existingPluginTables();
    Schema::table('plugin_releases', fn ($table) => $table->dropIndex('plugin_releases_status_created_idx'));

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('plugins'));
    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and($plan->differences)->toContain('plugin_releases: index plugin_releases_status_created_idx is missing');

    Schema::drop('plugin_moderator_notes');
    expect(app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('plugins'))->status)->toBe(AdoptionStatus::Partial);
});

it('is already adopted on a site that ran the package migration', function () {
    expect(Tables::name('plugin_releases'))->toBe('filagora_plugin_releases')
        ->and(Schema::hasTable('filagora_plugin_types'))->toBeTrue();
});

it('does not ask for the support-thread link when forums is not installed', function () {
    expect(app(SchemaRegistry::class)->extensionsFor('plugins'))->toBe([]);
});
