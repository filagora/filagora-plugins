# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- The six plugin tables (`plugin_types`, `plugins`, `plugin_releases`, `plugin_reports`,
  `plugin_subscriptions`, `plugin_moderator_notes`), their models, the `PluginStatus` and
  `PluginReportStatus` enums, and a schema snapshot that `filagora:adopt` checks.
- Release behaviour on the model: per-plugin `internal_version` numbering, the uploader taken from the
  signed-in user, `approved_at` kept through a quarantine or an archive, and the size, checksum and
  filename filled in from the stored file on `storage.disk`.
- An optional support-thread foreign key (`database/migrations/forums`) and its schema extension,
  registered only when `filagora-forums` is installed.

- The release lifecycle as services: `Releases` (submit a new plugin with its first release, or a release of an
  existing plugin; version rules; waiting releases replaced on request), `Moderation` (approve or reject a plugin or
  release, with notes), `Subscriptions` and `Reports`, with `ReleaseRefused` and `ModerationRefused` carrying a
  reason.
- Contracts to bind: `SubmissionPolicy`, `ArchiveInspector`, `SubscriberPreferences`, and `ReleaseExtension` for
  profile packages.
- An archive check before storage (`ZipArchiveInspector`) and a mend for backslash path separators.
- Events for every step, and default emails read from the language file (switch off with `mail.enabled`).
- The `filagora.plugins.moderate` and `filagora.plugins.auto_publish` abilities, remappable in `gates`.

- `Visibility`: the public, owner and staff views of plugins, types and releases, with entitlement-scoped types
  resolved through the access checker.
- `DownloadAccess` (contract and default), `ReleaseSelection`, and the `Compatibility` contract for a profile
  package that tracks what a release works with.
- With `filagora-search`: the `plugin_releases` index (`ReleaseSearchIndex`), a database fallback that understands
  the filter tree, `PluginSearch` and `PluginRanking`, and a job that keeps release documents right when a plugin
  or type changes.

- Archiving: `Archiving` (plan, archive, restore), the `filagora:plugins:archive-releases` command, `ArchiveGuard` for
  packages that want a release kept, and the `ReleasesArchived` / `ReleasesRestored` events.
- `Visibility::publicReleases($plugin, withArchived: true)` for a page that lists historical builds.

- Four staff screens in a **Plugins** navigation group (plugins, releases, reports, types), a notes panel on each
  plugin, and the moderation, archive and review steps as table actions. All are open to `filagora.plugins.moderate`.
- A privacy contributor (export and erasure), and, with `filagora-entitlements`, definitions for the ability keys and
  for the scopes set on plugin types.

### Differences from the site this was extracted from

- The release screen has no create page and no security-scan, AI-review or quarantine actions: submitting a
  release goes through `Releases`, and the scan actions belong to `filagora-plugins-security`.
- Plugin reports are reviewed with an action (and a note) instead of an edit form, so the reporter's text and the
  review cannot be changed together.
- The unused `navigation_group` setting is gone; the group is registered with core's navigation registry, which a
  host can relabel.

- Archiving keeps the newest release for each *version line of what a release works with* through the
  `Compatibility` contract, rather than for each Zen Cart version line; and a release with an unresolved
  serious security finding is kept through `ArchiveGuard`, which the security package will register.
- Archiving leaves one note per plugin touched (the original planned it but did not do it).

- The access checker decides who may see an entitlement-scoped type, so other access models work too; the
  original resolved the viewer's entitlements and matched scopes with wildcards in the query. The debug flag that
  switched every access check off is not carried over.
- The database search needs every word of the query, as the other engines do; the original matched the whole
  phrase.

- Submissions are refused by the service, not only by a route's middleware, when `submissions_enabled` is false.
- A file that is not a readable ZIP is refused; the original let it through and failed later.
- Two follower and author emails that the original sent from a controller are sent from events, so a host can
  replace them.

- Leaving the archive clears the archive bookkeeping (`status_before_archive`, `archived_at`,
  `archived_by_id`) for every route. The original compared the cast status to a string and so only
  cleared it when the archive service did it by hand.
