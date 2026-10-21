# Filagora Plugins

A library of plugins and their releases for Filagora: types, plugins, uploaded releases, reports,
subscriptions and staff notes, with the review, quarantine and archive states a release moves through.

> The package is at `0.1`: the API may change between `0.x` minor versions. There is no public
> frontend: it is models, services, staff screens and two optional sets of routes, and you build the
> pages.

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

## Archiving old releases

```bash
php artisan filagora:plugins:archive-releases                    # show the plan, change nothing
php artisan filagora:plugins:archive-releases --apply            # do it
php artisan filagora:plugins:archive-releases --restore --apply  # put archived releases back
```

Archived releases stay listed and (see `downloads.archived_releases`) downloadable, but leave search and the
security sweep. A release stays active when it is **the plugin's newest**, **uploaded within `archiving.keep_months`**
(18), **the newest for a version line** the plugin still works with (through `Compatibility`; switch off with
`--no-keep-per-line`), or **kept by a guard** (`ArchiveGuards::register()`, for example a security package keeping
a release with an unresolved serious finding). A plugin that is dead weight (rejected, in an inactive type,
moderated and untouched for a year, or ownerless with nothing newer than two years) keeps only its newest release
(`--no-whole-plugin` skips that). `--plugin=` (ids or slugs), `--limit=`, `--keep-months=` and `--actor=` narrow
a run.

**A plugin never ends up with no active release**: the newest is never archived, whatever list of ids
`Archiving::archive()` is given. It records who archived and when, leaves one note on each plugin touched, and
`Archiving::restore()` has no limit. The service checks nobody's permission: the screen or command that offers
it must.

## Staff screens, privacy and entitlements

Register `FilagoraPluginsPlugin::make()` on a Filament panel for four screens in a **Plugins** navigation group,
all open only to someone the access checker allows `filagora.plugins.moderate`:

- **Plugins**: list (filter by status or type), create, edit, approve or reject (a rejection needs a note), change
  the status of several, and a **Staff notes** panel on each plugin.
- **Plugin releases**: list, edit, approve or reject, download the archive, change the status of several, and
  **Archive** / **Restore** in bulk (a plugin's last active release is never archived). Releases are submitted
  through `Releases`, which checks the archive, so there is no create page. Other packages add to this screen
  through `ReleaseScreens` (the security package puts its scan result, re-scan and quarantine actions and the
  scan history there):

  ```php
  app(\Filagora\Plugins\Filament\ReleaseScreens::class)->column('my.key', fn () => TextColumn::make('...'));
  ```

  `column`, `filter`, `action` (a row action or group), `bulkAction` and `relationManager` take a key of your own
  and a closure that builds the component when the screen is set up; registering a key again replaces it.
- **Plugin reports**: the open reports first; mark one reviewed or dismiss it with a note, and the reporter is told.
- **Plugin types**: name, slug, order, active, and the entitlement a member needs to see and download its plugins
  (picked from the definitions when `filagora-entitlements` is installed, typed in otherwise).

With `filagora-entitlements` installed, `filagora:entitlements:sync` creates a definition for each ability key and for
every scope set on a plugin type. The package registers a **privacy contributor** with core: the compliance
package's export covers a person's plugins, releases, followed plugins and reports, and erasure removes what is only
theirs (who they follow, what they reported) while the public plugins and releases, and staff notes, stay.

## The optional routes

Two sets of routes, both **off by default**; switch them on in `config/filagora-plugins.php` (`routes.api.enabled`,
`routes.downloads.enabled`, or `FILAGORA_PLUGINS_API_ENABLED` / `FILAGORA_PLUGINS_DOWNLOADS_ENABLED`).

**Downloads** (`routes.downloads.prefix`, `plugins` by default): `GET {prefix}/{slug}/download` serves the plugin's
newest active release and `GET {prefix}/{slug}/releases/{id}/download` one release, under the name it was uploaded
with. Anyone may download an active plugin of an active type; a type that requires an entitlement needs a signed-in
person who has it (a guest gets a 401, which your exception handler turns into a sign-in; someone without it, a 403).
Archived releases download while `downloads.archived_releases` is on. Staff who may moderate can fetch anything for
review in a browser session (never with a token). The count goes up only for downloads the public could make.

**JSON API** (`routes.api.prefix`, `api/v1` by default, route names `filagora-plugins.api.*`):

| Endpoint | What it does |
| --- | --- |
| `GET plugin-types` | The types the caller may see, with active plugin counts |
| `GET plugins` | Plugins, paged (`q`, `type`, `page`, `visibility=public\|owner\|staff`) |
| `GET plugins/{slug}` | One plugin with its releases (`visibility` too) |
| `GET plugins/{slug}/releases` and `.../releases/{version}` | Releases; one release |
| `GET plugins/{slug}/download/{version}` | Download details and link for a release |
| `POST plugins/updates` | `{target?, plugins: [{slug\|id, version}]}` returns the plugins with a newer release that works with `target` |
| `PATCH plugins/{slug}` | An owner or moderator changes name, description, repository, support thread |
| `GET\|POST\|DELETE plugins/{slug}/subscription` | Follow a plugin |
| `POST plugins/{slug}/releases` | Upload a release (`version`, `zip_file`, `changelog`, `supersede_pending_releases`) |
| `POST plugins/{slug}/releases/{version}/moderate` | Staff: `action=approve\|reject_permanently`, `note` |

What anyone sees comes from `Visibility`; uploads and moderation go through `Releases` and `Moderation`, so every rule
above applies. A refused upload is a 422 naming the field (a paused site is a 503).

**Who may call.** A browser session needs nothing more. A request with a **token** needs the ability the endpoint asks
for (`plugins:read`, `plugins:download`, `plugins:write`) on the token, and the matching capability key
(`routes.api.keys`, `api.plugins.read` and so on) allowed by the access checker for the token's owner; set a key to
`null` to ask for none. The package never reads a token: bind `ApiUsers` for Passport or anything else; the default
asks `routes.api.guard` (set it to `sanctum`) for the user and the user's `tokenCan()`.

**For profile packages.** `ApiFields` adds fields to every plugin and release and rules for extra upload fields (they
reach your `ReleaseExtension` in `ReleaseInput::$extra`); `PluginIdentifiers` teaches the update check the ids your
clients already use. Both default to nothing.

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
| `archiving.*` | 18 months, version lines on | What archiving keeps |
| `downloads.archived_releases` | `true` | Whether an archived release can still be downloaded |
| `search.candidates` | `300` | Most matching releases asked of the engine before ranking |
| `gates` | `filagora.plugins.moderate`, `.auto_publish` | The keys behind the abilities |
| `mail.*` | on, no support address | The default emails |
| `routes.api.*`, `routes.downloads.*` | off | The optional routes, their prefix, middleware, guard, capability keys and limits |
| `routes.plugin` | none | Named route of your plugin page, for links in mail |

## Testing

```bash
composer test
```

Tests run against a real MariaDB, never SQLite. Host and credentials come from your environment;
the database is always named `testing`.

## Licence

MIT. See [LICENSE](LICENSE).
