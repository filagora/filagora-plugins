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

### Differences from the site this was extracted from

- Submissions are refused by the service, not only by a route's middleware, when `submissions_enabled` is false.
- A file that is not a readable ZIP is refused; the original let it through and failed later.
- Two follower and author emails that the original sent from a controller are sent from events, so a host can
  replace them.

- Leaving the archive clears the archive bookkeeping (`status_before_archive`, `archived_at`,
  `archived_by_id`) for every route. The original compared the cast status to a string and so only
  cleared it when the archive service did it by hand.
