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

### Differences from the site this was extracted from

- Leaving the archive clears the archive bookkeeping (`status_before_archive`, `archived_at`,
  `archived_by_id`) for every route. The original compared the cast status to a string and so only
  cleared it when the archive service did it by hand.
