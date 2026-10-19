# AGENTS

Guidance for AI coding agents working in `filagora-plugins`. `CLAUDE.md` imports this file; edit here.

## What this is

**filagora/filagora-plugins**: A library of plugins and their releases, with moderation, reports, subscriptions, archiving and search, as a Filament plugin.

Part of Filagora, a family of MIT-licensed Laravel 13 / Filament 5 packages (forums, CMS, entitlements, logs, compliance, messages, search, push, memberships). Composer vendor `filagora`, namespace `Filagora\<Name>\`, default table prefix `filagora_`, commands `filagora:*`, env `FILAGORA_*`. Every package is pre-1.0 (`0.x`): the API may change between minor versions.

Dependencies are declared in `composer.json` (`require` is hard, `suggest` is optional). Other packages must keep working when an optional one is absent; each package's `tests/NoEntitlements`/`Switches` directory proves that.

## Layout

`src/` (service provider, `FilagoraPluginsPlugin` where there are screens, models, services, contracts, events, Filament resources), `config/filagora-plugins.php`, `database/migrations` + `database/schema.php`, `resources/`, `tests/` (Pest, Testbench: `testbench.yaml`).

## This package

- **Slices 1 to 3 of the plugin library:** the tables and models, the release lifecycle (submit, moderate, report, follow), and who sees what, downloads, release selection and search. Archiving of old releases is built too (`Archiving`, `filagora:plugins:archive-releases`); the Filament screens and the optional routes arrive later.
- `Archiving::archive()` must never leave a plugin without an active release; any new path that archives has to go through it. It checks nobody's permission, so a screen or command that offers it must.
- `Visibility` is the single source for what each audience sees: never query plugins or releases for a page, API response or search result without it. `DownloadAccess`, `Compatibility` and `ReleaseSearchIndex` / `PluginRanking` are the extension points for a profile package; search exists only when `filagora-search` is installed, so keep that integration optional (`registerSearch()`).
- `Releases`, `Moderation`, `Reports` and `Subscriptions` are the services a host calls. They throw `ReleaseRefused` / `ModerationRefused` (a reason enum plus a safe message) and dispatch events; the default emails are a listener (`SendPluginMail`) that `mail.enabled` switches off.
- Profile packages hook in through `ReleaseExtensions` and the events; the package's own attributes (status, plugin id) always win over an extension's.
- Six tables: `plugin_types`, `plugins`, `plugin_releases`, `plugin_reports`, `plugin_subscriptions`, `plugin_moderator_notes`, matching the live definitions of the site the package was extracted from, so an existing site can adopt them with `filagora:adopt`.
- What is deliberately *not* here: columns only one kind of plugin needs (an import id, an archive layout, a zip root) belong to a profile package that declares them as a schema extension; the links into other packages' tables (`forum_thread_id`, the two security-scan ids) are plain columns, and the foreign keys come from those packages' migrations. The forums link ships here as `database/migrations/forums` and is registered as a schema extension only when `filagora-forums` is installed.
- `PluginRelease` numbers its releases per plugin (`internal_version`), keeps `approved_at` through a quarantine or archive, and fills the size and checksum from the stored file on the configured disk.
- Scanning and quarantine decisions live in `filagora-plugins-security`; Zen Cart specifics (version compatibility, version-check endpoints) live in the Zen Cart profile. See `plugins-design.md` in the private planning repository.

## Commands

Run everything through DDEV from this directory (`ddev start` once; the `testing` database is created on the first `ddev restart`).

```bash
ddev composer install
ddev composer test      # Pest, on a real MariaDB
ddev composer lint      # Pint
ddev composer analyse   # Larastan (level 6)
```

Tests always run against MariaDB (database `testing`, forced in `phpunit.xml.dist`), never SQLite, so FULLTEXT, `ONLY_FULL_GROUP_BY` and collation behave like production. Do not weaken that to make a test pass. If a port is taken, `ddev poweroff` before starting another project's DDEV.

## Conventions

- PHP 8.4, `declare(strict_types=1)` in every source file (`tests/ArchTest.php` enforces it, plus no debug calls and no `env()` outside `config/`).
- Table names always go through core's `Tables::name('x')` (prefix-aware); user foreign keys through `UserKey`/`UserModel`. Never hard-code `filagora_` or `users`.
- Config holds no closures and is merged with the host's copy, so list only what differs. Env vars are `FILAGORA_`-prefixed.
- Authorization is one question, "may this user do this?", answered by core's `AccessChecker` from the package's `gates` config. Do not call a roles or permissions package directly.
- Extend through documented contracts, events and registries. Anything undocumented is `@internal`; public API follows SemVer.
- Migrations are mirrored by a snapshot in `database/schema.php` (`filagora:schema:dump`). A table change means updating the migration, the snapshot and the adoption check together, because `filagora:adopt` compares live tables against the snapshot.

## Definition of done

- Every behaviour change ships with tests; every bug fix with a regression test that fails before the fix.
- README and `CHANGELOG.md` (`[Unreleased]`) are updated in the same change.
- `composer test`, `composer lint` and `composer analyse` pass.
- Work on a branch and open a pull request (`gh pr merge --squash --delete-branch`). `master` is protected and squash-only.
- Confirm with the user before tagging a release, creating or deleting repositories or branches, or anything else outward-facing. Tags create the GitHub release from the matching `CHANGELOG.md` section; the user submits new packages to Packagist.

## Public repository: keep private details out

This repository is public and CI runs a leak scan (private patterns, any IPv4 literal, any email address not on a reserved test domain, gitleaks). Never write real hostnames, IP addresses, personal emails, the name of the site the packages were extracted from, or plan/infrastructure details into code, docs, fixtures, commit messages or PRs. Use `example.test`-style addresses.

## CI

`.github/workflows/ci.yml` calls the reusable workflows in `filagora/filagora-shared` at `@master` (tests, lint, leak scan, release). Fix CI problems there, not by copying logic into this repo.
