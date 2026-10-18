<?php

declare(strict_types=1);

use Filagora\Plugins\Contracts\ReleaseExtension;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Enums\ReleaseRefusal;
use Filagora\Plugins\Events\PluginSubmitted;
use Filagora\Plugins\Events\ReleasePublished;
use Filagora\Plugins\Events\ReleaseRejected;
use Filagora\Plugins\Events\ReleaseSubmitted;
use Filagora\Plugins\Exceptions\ReleaseRefused;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginModeratorNote;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Release\ReleaseExtensions;
use Filagora\Plugins\Release\ReleaseInput;
use Filagora\Plugins\Services\Releases;
use Filagora\Plugins\Tests\Support\GrantedKeys;
use Filagora\Plugins\Tests\Support\TestUser;
use Filagora\Plugins\Tests\Support\Zips;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Event::fake([PluginSubmitted::class, ReleaseSubmitted::class, ReleasePublished::class, ReleaseRejected::class]);
});

function submitNewPlugin(TestUser $user, string $name = 'Super Widget', string $version = '1.0.0'): PluginRelease
{
    return app(Releases::class)->submitPlugin($user, ['name' => $name, 'plugin_type_id' => pluginType()->id, 'description' => 'Does things'], releaseInput($version));
}

function refusedWith(Closure $work): ?ReleaseRefusal
{
    try {
        $work();
    } catch (ReleaseRefused $e) {
        return $e->reason;
    }

    return null;
}

it('puts a new plugin from an ordinary member in the moderation queue', function () {
    $author = TestUser::make('Ada');

    $release = submitNewPlugin($author);
    $plugin = $release->plugin;

    expect($plugin->status)->toBe(PluginStatus::MODERATED)
        ->and($release->status)->toBe(PluginStatus::MODERATED)
        ->and($plugin->owner_id)->toBe($author->id)
        ->and($plugin->slug)->toBe('super-widget')
        ->and($release->version)->toBe('v1.0.0')
        ->and($release->internal_version)->toBe(1)
        ->and($release->uploaded_by_id)->toBe($author->id)
        ->and($release->changelog)->toBe('Changes');

    Event::assertDispatched(PluginSubmitted::class);
    Event::assertDispatched(ReleaseSubmitted::class);
    Event::assertNotDispatched(ReleasePublished::class);
});

it('publishes at once for someone who may moderate or auto-publish', function (string $key) {
    $author = TestUser::make('Ada');
    app(GrantedKeys::class)->give($author, $key);

    $release = submitNewPlugin($author);

    expect($release->plugin->status)->toBe(PluginStatus::ACTIVE)
        ->and($release->status)->toBe(PluginStatus::ACTIVE)
        ->and($release->approved_at)->not->toBeNull();

    Event::assertDispatched(ReleasePublished::class, fn (ReleasePublished $e): bool => $e->moderator === null && $e->author->is($author));
})->with(['filagora.plugins.moderate', 'filagora.plugins.auto_publish']);

it('follows a remapped key for who may auto-publish', function () {
    config(['filagora-plugins.gates' => ['filagora.plugins.auto_publish' => 'operator.plugin.auto_publish', 'filagora.plugins.moderate' => 'operator.plugin.moderate']]);
    $author = TestUser::make('Ada');
    app(GrantedKeys::class)->give($author, 'operator.plugin.auto_publish');

    expect(submitNewPlugin($author)->status)->toBe(PluginStatus::ACTIVE);
});

it('stores the archive on the configured disk and keeps the name it was uploaded with', function () {
    $release = submitNewPlugin(TestUser::make('Ada'));

    expect($release->zip_path)->toStartWith('plugins/widget-')->toEndWith('.zip')
        ->and($release->zip_filename)->toBe('widget.zip');
    Storage::disk('local')->assertExists($release->zip_path);
    expect($release->zip_size)->toBeGreaterThan(0)->and($release->checksum_sha256)->toHaveLength(64);
});

it('gives each new plugin a slug of its own', function () {
    $author = TestUser::make('Ada');

    expect(submitNewPlugin($author, 'Same Name')->plugin->slug)->toBe('same-name')
        ->and(submitNewPlugin($author, 'Same Name')->plugin->slug)->toBe('same-name-1')
        ->and(submitNewPlugin($author, '!!!')->plugin->slug)->toBe('plugin');
});

it('refuses a plugin of a type that does not exist', function () {
    $reason = refusedWith(fn () => app(Releases::class)->submitPlugin(TestUser::make('Ada'), ['name' => 'X', 'plugin_type_id' => 999], releaseInput()));

    expect($reason)->toBe(ReleaseRefusal::UNKNOWN_TYPE)->and(Plugin::count())->toBe(0);
});

it('refuses everything while submissions are paused', function () {
    config(['filagora-plugins.submissions_enabled' => false]);
    $plugin = livePlugin();

    expect(refusedWith(fn () => submitNewPlugin(TestUser::make('Ada'))))->toBe(ReleaseRefusal::SUBMISSIONS_PAUSED)
        ->and(refusedWith(fn () => app(Releases::class)->submitRelease(TestUser::make('Bo'), $plugin, releaseInput('2.0.0'))))->toBe(ReleaseRefusal::SUBMISSIONS_PAUSED);
});

it('refuses a version that is not two to four numbers', function (string $version) {
    expect(refusedWith(fn () => submitNewPlugin(TestUser::make('Ada'), 'X', $version)))->toBe(ReleaseRefusal::INVALID_VERSION)
        ->and(Plugin::count())->toBe(0);
})->with(['1', '1.0-beta', 'latest', 'five.parts']);

it('refuses a version the plugin already has, however it is spelled', function () {
    $plugin = livePlugin();

    expect(refusedWith(fn () => app(Releases::class)->submitRelease(TestUser::make('Ada'), $plugin, releaseInput('1.0.0'))))->toBe(ReleaseRefusal::DUPLICATE_VERSION)
        ->and(refusedWith(fn () => app(Releases::class)->submitRelease(TestUser::make('Ada'), $plugin, releaseInput('v1.0.0'))))->toBe(ReleaseRefusal::DUPLICATE_VERSION);
});

it('can leave one release out of the duplicate check, for editing it', function () {
    $plugin = livePlugin();
    $release = $plugin->releases->first();

    expect(app(Releases::class)->validateVersion($plugin, '1.0.0', $release->id))->toBe('v1.0.0')
        ->and(fn () => app(Releases::class)->validateVersion($plugin, '1.0.0'))->toThrow(ReleaseRefused::class);
});

it('refuses an unsafe archive without storing it or creating anything', function () {
    $input = new ReleaseInput('1.0.0', Zips::make(['../evil.php' => 'x']));

    $reason = refusedWith(fn () => app(Releases::class)->submitPlugin(TestUser::make('Ada'), ['name' => 'Evil', 'plugin_type_id' => pluginType()->id], $input));

    expect($reason)->toBe(ReleaseRefusal::UNSAFE_PATH)
        ->and(Plugin::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Event::assertNotDispatched(ReleaseSubmitted::class);
});

it('numbers a new release after the plugin\'s last one', function () {
    $plugin = livePlugin(TestUser::make('Ada'));

    $release = app(Releases::class)->submitRelease(TestUser::make('Bo'), $plugin, releaseInput('1.1.0'));

    expect($release->internal_version)->toBe(2)->and($release->version)->toBe('v1.1.0');
    Event::assertNotDispatched(PluginSubmitted::class);
    Event::assertDispatched(ReleaseSubmitted::class);
});

it('asks before replacing releases that are still waiting for review', function () {
    $plugin = livePlugin(TestUser::make('Ada'), PluginStatus::MODERATED);

    try {
        app(Releases::class)->submitRelease(TestUser::make('Ada2'), $plugin, releaseInput('1.1.0'));
        $this->fail('Expected a refusal');
    } catch (ReleaseRefused $e) {
        expect($e->reason)->toBe(ReleaseRefusal::PENDING_RELEASES)
            ->and($e->field())->toBe('supersede_pending_releases')
            ->and($e->getMessage())->toContain('v1.0.0');
    }

    expect(PluginRelease::count())->toBe(1);
});

it('rejects the waiting releases, with a note, when the author agrees to replace them', function () {
    $author = TestUser::make('Ada');
    $plugin = livePlugin($author, PluginStatus::MODERATED);
    $waiting = $plugin->releases->first();

    $new = app(Releases::class)->submitRelease($author, $plugin, releaseInput('1.1.0', supersede: true));

    expect($waiting->fresh()->status)->toBe(PluginStatus::REJECTED_FINAL)
        ->and($new->fresh()->status)->toBe(PluginStatus::MODERATED)
        ->and(PluginModeratorNote::where('plugin_id', $plugin->id)->first()->note)->toContain('v1.0.0')->toContain('Replaced by v1.1.0');
    Event::assertDispatched(ReleaseRejected::class, fn (ReleaseRejected $e): bool => $e->superseded && $e->release->is($waiting) && $e->except->is($author));
});

it('does not count a live or rejected release as waiting', function () {
    $author = TestUser::make('Ada');
    $plugin = livePlugin($author, PluginStatus::ACTIVE);

    expect(app(Releases::class)->pendingFor($plugin))->toHaveCount(0)
        ->and(app(Releases::class)->submitRelease($author, $plugin, releaseInput('1.1.0'))->status)->toBe(PluginStatus::MODERATED);
});

it('lets a profile package check the submission, add its columns and act once the release exists, but not override what the package decides', function () {
    $seen = [];
    app(ReleaseExtensions::class)->register(new class($seen) implements ReleaseExtension
    {
        public function __construct(public array &$seen) {}

        public function prepare(?Plugin $plugin, ReleaseInput $input, string $canonicalVersion): array
        {
            $this->seen[] = ['prepare', $plugin?->id, $canonicalVersion, $input->extra];

            return ['changelog' => 'from the extension', 'status' => 'active', 'quarantine_note' => 'kept'];
        }

        public function created(Plugin $plugin, PluginRelease $release, ReleaseInput $input): void
        {
            $this->seen[] = ['created', $release->id];
        }
    });

    $release = app(Releases::class)->submitPlugin(TestUser::make('Ada'), ['name' => 'Ext', 'plugin_type_id' => pluginType()->id], releaseInput('2.0', ['flag' => true]));

    expect($release->quarantine_note)->toBe('kept')
        ->and($release->changelog)->toBe('Changes')
        ->and($release->status)->toBe(PluginStatus::MODERATED)
        ->and($seen[0])->toBe(['prepare', null, 'v2.0', ['flag' => true]])
        ->and($seen[1])->toBe(['created', $release->id]);
});

it('stores nothing and creates nothing when an extension refuses', function () {
    app(ReleaseExtensions::class)->register(new class implements ReleaseExtension
    {
        public function prepare(?Plugin $plugin, ReleaseInput $input, string $canonicalVersion): array
        {
            throw new ReleaseRefused(ReleaseRefusal::REJECTED_BY_EXTENSION, 'Nope.');
        }

        public function created(Plugin $plugin, PluginRelease $release, ReleaseInput $input): void {}
    });

    expect(refusedWith(fn () => submitNewPlugin(TestUser::make('Ada'))))->toBe(ReleaseRefusal::REJECTED_BY_EXTENSION)
        ->and(Plugin::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('takes back the stored file and the plugin when something fails after the archive is stored', function () {
    app(ReleaseExtensions::class)->register(new class implements ReleaseExtension
    {
        public function prepare(?Plugin $plugin, ReleaseInput $input, string $canonicalVersion): array
        {
            return [];
        }

        public function created(Plugin $plugin, PluginRelease $release, ReleaseInput $input): void
        {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => submitNewPlugin(TestUser::make('Ada')))->toThrow(RuntimeException::class, 'boom')
        ->and(Plugin::count())->toBe(0)
        ->and(PluginRelease::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('mends backslash separators in the archive it stores', function () {
    $input = new ReleaseInput('1.0.0', Zips::make(['widget\\a.php' => 'a']));
    $release = app(Releases::class)->submitPlugin(TestUser::make('Ada'), ['name' => 'Win', 'plugin_type_id' => pluginType()->id], $input);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($release->zip_path), ZipArchive::RDONLY);

    expect($zip->getFromName('widget/a.php'))->toBe('a');
});

it('lists version numbers for a message', function () {
    $plugin = livePlugin(null, PluginStatus::MODERATED);
    PluginRelease::create(['plugin_id' => $plugin->id, 'version' => 'v1.0.1', 'zip_path' => 'x', 'status' => PluginStatus::MODERATED]);

    expect(app(Releases::class)->versionList(app(Releases::class)->pendingFor($plugin)))->toBe('v1.0.0, v1.0.1');
});
