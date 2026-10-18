# Filagora Plugins

A library of plugins and their releases for Filagora: types, plugins, uploaded releases, reports,
subscriptions and staff notes, with the review, quarantine and archive states a release moves through.

> The package is at `0.1`: the API may change between `0.x` minor versions. This first release is
> the data layer: tables, models and statuses. Release submission and validation, moderation,
> subscriptions, archiving, the Filament screens and the optional download and JSON API routes follow.

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
| `navigation_group` | `Plugins` | Filament navigation group |

## Testing

```bash
composer test
```

Tests run against a real MariaDB, never SQLite. Host and credentials come from your environment;
the database is always named `testing`.

## Licence

MIT. See [LICENSE](LICENSE).
