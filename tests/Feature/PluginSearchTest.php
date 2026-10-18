<?php

declare(strict_types=1);

use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Models\PluginType;
use Filagora\Plugins\Search\PluginRanking;
use Filagora\Plugins\Search\PluginSearch;
use Filagora\Plugins\Search\ReleaseDatabaseSearch;
use Filagora\Plugins\Search\ReleaseSearchIndex;
use Filagora\Plugins\Services\ReleaseSelection;
use Filagora\Plugins\Tests\Support\GrantedKeys;
use Filagora\Plugins\Tests\Support\TestUser;
use Filagora\Search\Contracts\Filter;
use Filagora\Search\Engines\ArrayEngine;
use Filagora\Search\Filters\Equals;
use Filagora\Search\Filters\Range;
use Filagora\Search\Support\SearchEngines;
use Filagora\Search\Support\SearchQuery;
use Illuminate\Support\Carbon;

/*
 * Every scenario runs on the array engine (documents in memory, filters evaluated in PHP) and on the
 * database engine (the same query answered from the tables), and must give the same answer.
 */

dataset('engines', ['array', 'database']);

function searchOn(string $engine): ArrayEngine
{
    $array = new ArrayEngine;
    app(SearchEngines::class)->extend('array', fn () => $array);
    config(['filagora-search.engine' => $engine]);
    $array->syncSettings(app(ReleaseSearchIndex::class)->definition());

    return $array;
}

function searchable(string $name, string $description = '', string $version = '1.0.0', ?string $changelog = null, ?PluginType $type = null, array $release = []): Plugin
{
    $plugin = Plugin::create([
        'name' => $name,
        'slug' => str($name)->slug()->toString(),
        'description' => $description,
        'plugin_type_id' => ($type ?? pluginType())->id,
        'status' => PluginStatus::ACTIVE,
    ]);
    addRelease($plugin, $version, PluginStatus::ACTIVE, ['changelog' => $changelog] + $release);

    return $plugin->refresh();
}

/**
 * @return list<string>
 */
function found(string $text = '', ?TestUser $viewer = null, ?int $typeId = null, string $target = '', ?Filter $extra = null): array
{
    return app(PluginSearch::class)->ranked($text, $viewer, $typeId, $target, $extra)->pluck('name')->all();
}

it('finds a plugin by its name, its description, a changelog line or a version', function (string $engine) {
    searchOn($engine);
    searchable('Shipping Calculator', 'Works out postage', '2.4.0', 'Fixed rounding of kilograms');
    searchable('Banner Rotator', 'Rotates banners');

    expect(found('calculator'))->toBe(['Shipping Calculator'])
        ->and(found('postage'))->toBe(['Shipping Calculator'])
        ->and(found('kilograms'))->toBe(['Shipping Calculator'])
        ->and(found('2.4.0'))->toBe(['Shipping Calculator'])
        ->and(found('nothing like it'))->toBe([])
        ->and(found('', null))->toHaveCount(2);
})->with('engines');

it('needs every word of the query to be found, in any order and in different places', function (string $engine) {
    searchOn($engine);
    searchable('Shipping Calculator', 'Works out postage', '2.4.0', 'Fixed rounding');
    searchable('Banner Rotator', 'Rotates banners', changelog: 'Fixed rounding');

    expect(found('rounding shipping'))->toBe(['Shipping Calculator'])
        ->and(found('rounding'))->toHaveCount(2)
        ->and(found('shipping banners'))->toBe([]);
})->with('engines');

it('leaves out what the public may not see', function (string $engine) {
    searchOn($engine);
    $off = PluginType::create(['name' => 'Off', 'slug' => 'off', 'is_active' => false]);
    $members = PluginType::create(['name' => 'Members', 'slug' => 'members', 'required_entitlement_scope' => 'plugins.members']);
    searchable('Widget Visible');
    searchable('Widget Hidden Type', type: $off);
    searchable('Widget Members Only', type: $members);
    Plugin::create(['name' => 'Widget Waiting', 'slug' => 'waiting', 'plugin_type_id' => pluginType()->id, 'status' => PluginStatus::MODERATED]);
    $oneRelease = Plugin::create(['name' => 'Widget Unreleased', 'slug' => 'unreleased', 'plugin_type_id' => pluginType()->id, 'status' => PluginStatus::ACTIVE]);
    addRelease($oneRelease, '1.0.0', PluginStatus::MODERATED);

    expect(found('widget'))->toBe(['Widget Visible']);
})->with('engines');

it('shows a members-only type to someone allowed it', function (string $engine) {
    searchOn($engine);
    $members = PluginType::create(['name' => 'Members', 'slug' => 'members', 'required_entitlement_scope' => 'plugins.members']);
    searchable('Widget Members Only', type: $members);
    $member = TestUser::make('Mem');
    app(GrantedKeys::class)->give($member, 'plugins.members');

    expect(found('widget', $member))->toBe(['Widget Members Only'])
        ->and(found('widget', TestUser::make('Other')))->toBe([])
        ->and(found('widget'))->toBe([]);
})->with('engines');

it('can be narrowed to one type', function (string $engine) {
    searchOn($engine);
    $other = PluginType::create(['name' => 'Other', 'slug' => 'other']);
    searchable('Widget One');
    searchable('Widget Two', type: $other);

    expect(found('widget', null, $other->id))->toBe(['Widget Two'])
        ->and(found('widget', null, pluginType()->id))->toBe(['Widget One']);
})->with('engines');

it('can be narrowed to what works with a version, when compatibility is tracked', function (string $engine) {
    searchOn($engine);
    $compatibility = fakeCompatibility();
    $old = searchable('Widget Old');
    $new = searchable('Widget New');
    $compatibility->declare($old->releases->first(), '1.5.7');
    $compatibility->declare($new->releases->first(), '2.0.0');
    // The index read the compatibility when the releases were saved; save them again so it sees it.
    PluginRelease::query()->each(fn (PluginRelease $release) => $release->touch());

    expect(found('widget', null, null, '2.0.0'))->toBe(['Widget New'])
        ->and(found('widget', null, null, '1.5.7'))->toBe(['Widget Old'])
        ->and(found('widget', null, null, '9.9.9'))->toBe([]);
})->with('engines');

it('takes an extra filter from the caller', function (string $engine) {
    searchOn($engine);
    $first = searchable('Widget One');
    searchable('Widget Two');

    expect(found('widget', null, null, '', new Equals('plugin_id', $first->id)))->toBe(['Widget One']);
})->with('engines');

it('hands back each plugin once with all its active releases, not only the ones that matched', function (string $engine) {
    searchOn($engine);
    $plugin = searchable('Widget', version: '1.0.0', changelog: 'zebra stripes');
    addRelease($plugin, '2.0.0', PluginStatus::ACTIVE, ['changelog' => 'zebra again']);
    addRelease($plugin, '3.0.0', PluginStatus::MODERATED, ['changelog' => 'zebra later']);

    $results = app(PluginSearch::class)->ranked('zebra');

    expect($results)->toHaveCount(1)
        ->and($results->first()->releases)->toHaveCount(2)
        ->and($results->first()->search_canonical_release->version)->toBe('2.0.0');
})->with('engines');

it('puts a name match before a description match', function (string $engine) {
    searchOn($engine);
    searchable('Shipping Helper', 'Does other things');
    searchable('Other Plugin', 'Helps with shipping');

    expect(found('shipping'))->toBe(['Shipping Helper', 'Other Plugin']);
})->with('engines');

it('puts an exact name before one that merely contains the word', function (string $engine) {
    searchOn($engine);
    searchable('Super Widget Pro');
    searchable('Widget');
    searchable('Mega widgets');

    expect(found('widget'))->toBe(['Widget', 'Super Widget Pro', 'Mega widgets']);
})->with('engines');

it('prefers the more recently updated plugin, then the more downloaded, when the text matches equally', function (string $engine) {
    searchOn($engine);
    Carbon::setTestNow('2026-10-18 12:00:00');
    searchable('Widget Ancient', release: ['approved_at' => now()->subYears(10)]);
    searchable('Widget Fresh', release: ['approved_at' => now()->subDays(10)]);

    expect(found('widget'))->toBe(['Widget Fresh', 'Widget Ancient']);

    Carbon::setTestNow();
})->with('engines');

it('breaks a tie on downloads, then on the earlier plugin', function (string $engine) {
    searchOn($engine);
    Carbon::setTestNow('2026-10-18 12:00:00');
    $stamp = ['approved_at' => now()->subDays(5)];
    searchable('Widget A', release: $stamp + ['download_count' => 10]);
    searchable('Widget B', release: $stamp + ['download_count' => 1000]);
    searchable('Widget C', release: $stamp + ['download_count' => 10]);

    expect(found('widget'))->toBe(['Widget B', 'Widget A', 'Widget C']);

    Carbon::setTestNow();
})->with('engines');

it('takes a version in the query to be the one wanted, and shows the release that fits it', function (string $engine) {
    searchOn($engine);
    $compatibility = fakeCompatibility();
    $plugin = searchable('Widget', version: '1.0.0', changelog: 'for the old cart 1.5');
    $newer = addRelease($plugin, '2.0.0', PluginStatus::ACTIVE, ['changelog' => 'for the new cart 1.5']);
    $compatibility->declare($plugin->releases()->where('version', '1.0.0')->first(), '1.5');
    $compatibility->declare($newer, '2.1');
    $plugin->releases()->each(fn (PluginRelease $release) => $release->touch());

    $plain = app(PluginSearch::class)->ranked('widget')->first();
    $forOld = app(PluginSearch::class)->ranked('widget 1.5')->first();

    expect($plain->search_canonical_release->version)->toBe('2.0.0')
        ->and($forOld->search_canonical_release->version)->toBe('1.0.0');
})->with('engines');

it('lets a profile package add its own nudge to the score', function (string $engine) {
    searchOn($engine);
    searchable('Widget Plain');
    searchable('Widget Special');
    app()->instance(PluginRanking::class, new class(app(ReleaseSelection::class)) extends PluginRanking
    {
        protected function extraBonus(Plugin $plugin, PluginRelease $canonical): int
        {
            return $plugin->name === 'Widget Special' ? 1000 : 0;
        }
    });
    app()->forgetInstance(PluginSearch::class);

    expect(found('widget'))->toBe(['Widget Special', 'Widget Plain']);
})->with('engines');

it('pages the ranked plugins', function (string $engine) {
    searchOn($engine);
    foreach (range(1, 5) as $n) {
        searchable("Widget {$n}");
    }

    $page = app(PluginSearch::class)->plugins('widget', page: 2, perPage: 2);

    expect($page->total())->toBe(5)
        ->and($page->count())->toBe(2)
        ->and($page->currentPage())->toBe(2)
        ->and(app(PluginSearch::class)->plugins('widget', page: 3, perPage: 2)->count())->toBe(1);
})->with('engines');

it('describes a release as a document, with the plugin\'s words copied in', function () {
    $compatibility = fakeCompatibility();
    $plugin = searchable('Widget', 'Does things', '1.2.0', 'Added a thing', release: ['download_count' => 7, 'approved_at' => Carbon::parse('2026-01-02 03:04:05')]);
    $release = $plugin->releases->first();
    $compatibility->declare($release, '2.0.0');

    $document = app(ReleaseSearchIndex::class)->toDocument($release);

    expect($document->id)->toBe($release->id)
        ->and($document->fields)->toMatchArray([
            'plugin_id' => $plugin->id,
            'plugin_type_id' => $plugin->plugin_type_id,
            'plugin_name' => 'Widget',
            'plugin_slug' => 'widget',
            'plugin_description' => 'Does things',
            'plugin_status' => 'active',
            'type_is_active' => true,
            'status' => 'active',
            'version' => '1.2.0',
            'changelog' => 'Added a thing',
            'download_count' => 7,
            'approved_at_ts' => Carbon::parse('2026-01-02 03:04:05')->getTimestamp(),
            'compatibility' => ['2.0.0'],
        ]);
});

it('indexes only active releases of active plugins in active types', function () {
    $index = app(ReleaseSearchIndex::class);
    $plugin = searchable('Widget');
    $active = $plugin->releases->first();
    $waiting = addRelease($plugin, '2.0.0', PluginStatus::MODERATED);
    $quarantined = addRelease($plugin, '3.0.0', PluginStatus::QUARANTINED);

    expect($index->shouldBeSearchable($active))->toBeTrue()
        ->and($index->shouldBeSearchable($waiting))->toBeFalse()
        ->and($index->shouldBeSearchable($quarantined))->toBeFalse()
        ->and($index->expectedCount())->toBe(1)
        ->and($index->query()->count())->toBe(1);

    $plugin->update(['status' => PluginStatus::MODERATED]);
    expect($index->shouldBeSearchable($active->fresh()))->toBeFalse()->and($index->expectedCount())->toBe(0);

    $plugin->update(['status' => PluginStatus::ACTIVE]);
    $plugin->type->update(['is_active' => false]);
    expect($index->shouldBeSearchable($active->fresh()))->toBeFalse()->and($index->expectedCount())->toBe(0);
});

it('keeps the documents right when a plugin is renamed, or its type is switched off', function () {
    $engine = searchOn('array');
    $plugin = searchable('Widget Original');

    expect(found('original'))->toBe(['Widget Original']);

    $plugin->update(['name' => 'Gadget Renamed', 'slug' => 'gadget-renamed']);

    expect(found('original'))->toBe([])
        ->and(found('renamed'))->toBe(['Gadget Renamed']);

    $plugin->type->update(['is_active' => false]);

    expect(found('renamed'))->toBe([]);

    $plugin->type->update(['is_active' => true]);

    expect(found('renamed'))->toBe(['Gadget Renamed'])
        ->and($engine->stats(app(ReleaseSearchIndex::class)->definition())?->documents)->toBe(1);
});

it('takes a release out of the index when it is no longer live', function () {
    searchOn('array');
    $plugin = searchable('Widget');
    $release = $plugin->releases->first();

    expect(found('widget'))->toBe(['Widget']);

    $release->update(['status' => PluginStatus::QUARANTINED]);

    expect(found('widget'))->toBe([]);

    $release->update(['status' => PluginStatus::ACTIVE]);

    expect(found('widget'))->toBe(['Widget']);

    $release->delete();

    expect(found('widget'))->toBe([]);
});

it('refuses, from the database, a filter on a field it does not know', function () {
    expect(fn () => app(ReleaseDatabaseSearch::class)->search(new SearchQuery(filter: new Equals('colour', 'red'))))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ReleaseDatabaseSearch::class)->search(new SearchQuery(filter: new Range('download_count', 1))))->toThrow(InvalidArgumentException::class);
});

it('lists the fields a search may filter on, and lets a profile add more', function () {
    $definition = app(ReleaseSearchIndex::class)->definition();

    expect($definition->name)->toBe('plugin_releases')
        ->and($definition->filterable)->toContain('plugin_type_id', 'compatibility', 'status')
        ->and($definition->sortable)->toBe(['approved_at_ts', 'download_count']);
});
