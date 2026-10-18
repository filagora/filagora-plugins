# Filagora Plugins

A library of plugins and their releases for Filagora: types, plugins, uploaded releases, reports,
subscriptions and staff notes, with the review, quarantine and archive states a release moves through.

> The package is at `0.1`: the API may change between `0.x` minor versions. There is no frontend:
> it is models and services, and you build the pages. Archiving old releases, the Filament screens
> and the optional download and JSON API routes follow.

## Requirements

PHP 8.4, Laravel 13, Filament 5 and `filagora/filagora-core`. Tested against MariaDB 11.4.

## Tables

`plugin_types`, `plugins`, `plugin_releases`, `plugin_reports`, `plugin_subscriptions` and
`plugin_moderator_notes` (prefixed `filagora_` by default). A site that already has these tables can
adopt them with `php artisan filagora:adopt`. Models can be swapped in `config/filagora-plugins.php`.

- A **release** belongs to a plugin and carries its version, a per-plugin `internal_version` (numbered
  for you), the stored archive (`zip_path`, with the size and SHA-256 filled in from the file on the
  configured disk), a changelog, and its status.
- **Status** is `active`, `moderated`, `rejected_final`, `quarantined` or `archived`; only `active` is
  public. A quarantine or an archive keeps the release's `approved_at` and remembers the status it
  came from, so lifting it restores the release exactly.
- Columns only one kind of plugin needs (for example an import id or an archive layout) are not here:
  a profile package declares them as a schema extension. The columns that point at other packages'
  tables (`forum_thread_id`, the security-scan ids) are plain columns; with
  [`filagora/filagora-forums`](https://github.com/filagora/filagora-forums) installed, the migration in
  `database/migrations/forums` adds the support-thread foreign key.

## Using it

```php
$releases = app(\Filagora\Plugins\Services\Releases::class);

// A new plugin with its first release. Throws ReleaseRefused with a reason and the field it belongs to.
$release = $releases->submitPlugin($user, ['name' => 'Super Widget', 'plugin_type_id' => $type->id], new ReleaseInput('1.0.0', $request->file('zip_file'), changelog: 'First'));

// Another release of a plugin that exists.
$releases->submitRelease($user, $plugin, new ReleaseInput('1.1.0', $request->file('zip_file'), supersedePending: true));

$moderation = app(\Filagora\Plugins\Services\Moderation::class);
$moderation->moderateRelease($moderator, $plugin, $release, ModerationAction::APPROVE);

app(\Filagora\Plugins\Services\Subscriptions::class)->subscribe($user, $plugin);
app(\Filagora\Plugins\Services\Reports::class)->report($user, $plugin, 'Looks like malware');
```

- **Who is published at once.** Someone the access checker allows `filagora.plugins.moderate` or
  `filagora.plugins.auto_publish` goes live without review; everyone else waits (`moderated`). Bind your own
  `SubmissionPolicy` to decide another way. Remap the keys in `filagora-plugins.gates`.
- **Versions.** A version is two to four numbers with an optional `v`; it is stored as `v1.0.1`, and a plugin
  refuses a version it has under another spelling. A release gets the next `internal_version` for its plugin.
- **Replacing waiting releases.** If a plugin has releases still awaiting review, a new one is refused until the
  submitter agrees (`supersedePending`); the waiting ones are then rejected for good, each with a note.
- **Archives.** An upload is checked before it is stored (`ArchiveInspector`): it must be a ZIP, hold no path that
  escapes its folder or contains a space, and stay under `archive.max_entries` and `archive.max_uncompressed_bytes`.
  Backslash path separators are mended. Bind your own inspector to check more.
- **Moderation** approves or rejects (a rejection needs a note) and leaves a note on the plugin. Approving the first
  release of a plugin still awaiting review approves the plugin too.
- **Profile packages** add what only their kind of plugin needs through `ReleaseExtensions::register()`: extra
  checks on the archive, extra columns on the release, extra rows once it exists. They cannot override the
  package's own attributes, such as the status.
- **Events** let you do the rest: `PluginSubmitted`, `ReleaseSubmitted`, `ReleasePublished` (with the moderator,
  when one approved it), `ReleaseRejected`, `PluginModerated`, `SubscribersToNotify`, `PluginReported` and
  `PluginReportReviewed`. A security scan listens to `ReleaseSubmitted`.
- **Mail.** The default emails (receipts, approvals and rejections, a note to followers, reports) read their words
  from `resources/lang/en/notifications.php` and link to the route named in `routes.plugin`. Set `mail.enabled` to
  false to send your own from the events. `mail.support_email` is told of new submissions and reports.
- **Followers** choose whether to hear through your `SubscriberPreferences` (everyone, by default).
- **Kill switch.** `submissions_enabled` set to false refuses every new plugin and release.

## Who sees what, downloads and search

```php
$visibility = app(\Filagora\Plugins\Services\Visibility::class);
$visibility->publicPlugins($viewer, typeSlug: 'tools', search: 'shipping')->paginate();
$visibility->findOwnerPlugin($slug, $owner);          // their own plugin in any status
$visibility->staffPlugins($staff)->get();             // for someone who may moderate

app(\Filagora\Plugins\Contracts\DownloadAccess::class)->canDownloadRelease($viewer, $release);
app(\Filagora\Plugins\Services\ReleaseSelection::class)->latest($plugin->releases, target: '2.1.0');
app(\Filagora\Plugins\Search\PluginSearch::class)->plugins('shipping', $viewer, page: 1);
```

- **`Visibility`** is the one place that decides what each audience sees: the public sees active plugins of active
  types (and their active releases); an owner also sees their own plugins and releases in any status; staff who may
  moderate see everything in an active type. A type can require an entitlement scope, asked of the access checker.
  Build every page, API response and search result on these queries.
- **`DownloadAccess`** says who may download. The default needs an active plugin of an active type and, when the type
  requires a scope, the access checker's yes; a release must be active, or archived while `downloads.archived_releases`
  is on. Bind your own to decide another way.
- **`ReleaseSelection`** picks the newest release (by version number, then by when it was touched) and whether a
  version is newer than another. It can pick the newest release that works with a target such as a software version,
  through the `Compatibility` contract. With nothing bound every release works with every target; a profile package
  that tracks compatibility binds its own (it also supplies the relations to load and a database restriction).
- **Search** (with [`filagora/filagora-search`](https://github.com/filagora/filagora-search) installed): releases are
  indexed (`plugin_releases`), so a version or a changelog line can match; `PluginSearch` collapses the matches to
  plugins with all their active releases, drops what the viewer may not see, ranks them (name, description,
  changelog, version, fit with a version typed in the query, downloads, recency) and pages them. Every word must be
  found, in any field. The index is kept right when a release, plugin or type changes. A profile package adds
  document fields by subclassing `ReleaseSearchIndex` and score nudges by subclassing `PluginRanking`.

## Install

```bash
composer require filagora/filagora-plugins
php artisan vendor:publish --tag=filagora-plugins-config
php artisan migrate
```

Register the plugin on a Filament panel:

```php
->plugin(\Filagora\Plugins\FilagoraPluginsPlugin::make())
```

A site that already has the tables runs `php artisan filagora:adopt` instead of migrating.

## Configuration

`config/filagora-plugins.php` is merged with the host's copy, so list only what differs.

| Key | Default | Meaning |
| --- | --- | --- |
| `models.*` | the package models | Swap a model for your own subclass |
| `storage.disk` | `local` (`FILAGORA_PLUGINS_DISK`) | Disk release archives are stored on |
| `submissions_enabled` | `true` | Kill switch for new plugins and releases |
| `archive.*` | 5000 files, 200 MB | Limits for an uploaded archive |
| `downloads.archived_releases` | `true` | Whether an archived release can still be downloaded |
| `search.candidates` | `300` | Most matching releases asked of the engine before ranking |
| `gates` | `filagora.plugins.moderate`, `.auto_publish` | The keys behind the abilities |
| `mail.*` | on, no support address | The default emails |
| `routes.plugin` | none | Named route of your plugin page, for links in mail |
| `navigation_group` | `Plugins` | Filament navigation group |

## Testing

```bash
composer test
```

Tests run against a real MariaDB, never SQLite. Host and credentials come from your environment;
the database is always named `testing`.

## Licence

MIT. See [LICENSE](LICENSE).
