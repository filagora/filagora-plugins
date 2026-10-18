<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserKey;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The plugin library, in the shape of the site it was extracted from: types, plugins, their
 * releases, and the reports, subscriptions and moderator notes that hang off a plugin.
 *
 * Three columns point at tables this package does not own: `plugins.forum_thread_id` (forums) and
 * `plugin_releases.latest_security_scan_id` / `quarantine_scan_id` (the security package). They are
 * plain nullable columns with the index the site has; the foreign keys come from the migrations of
 * those packages, so this package installs on its own. Columns that only mean something for one
 * kind of plugin (a profile package's import id, archive layout) are added by that profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('plugin_types'), function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 64)->unique();
            $table->text('description')->nullable();
            $table->string('required_entitlement_scope')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'position', 'name'], 'plugin_types_active_position_name_idx');
        });

        Schema::create(Tables::name('plugins'), function (Blueprint $table): void {
            $table->id();
            $table->string('name', 180);
            $table->string('slug', 180)->unique();
            $table->unsignedBigInteger('plugin_type_id');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('forum_thread_id')->nullable();
            $table->string('github_repository_url', 500)->nullable();
            UserKey::column($table, 'owner_id')->nullable();
            $table->string('status', 16)->default('moderated')->index();
            $table->timestamps();

            $table->index('plugin_type_id', 'plugins_plugin_type_id_foreign');
            $table->index('forum_thread_id', 'plugins_forum_thread_id_foreign');
            $table->index(['status', 'plugin_type_id', 'updated_at', 'created_at'], 'plugins_status_type_updated_idx');
            $table->index(['status', 'updated_at', 'created_at'], 'plugins_status_updated_idx');
            $table->index(['updated_at', 'id'], 'plugins_updated_at_id_idx');

            $table->foreign('plugin_type_id')->references('id')->on(Tables::name('plugin_types'))->cascadeOnDelete();
            $table->foreign('owner_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });

        Schema::create(Tables::name('plugin_releases'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('plugin_id');
            UserKey::column($table, 'uploaded_by_id')->nullable();
            $table->string('version', 40);
            $table->unsignedInteger('internal_version');
            $table->string('zip_path');
            $table->string('zip_filename')->nullable();
            $table->unsignedBigInteger('zip_size')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->text('changelog')->nullable();
            $table->unsignedBigInteger('latest_security_scan_id')->nullable();
            $table->string('status_before_quarantine', 16)->nullable();
            $table->timestamp('quarantined_at')->nullable();
            $table->unsignedBigInteger('quarantine_scan_id')->nullable();
            $table->timestamp('quarantine_cleared_at')->nullable();
            UserKey::column($table, 'quarantine_cleared_by_id')->nullable();
            $table->text('quarantine_note')->nullable();
            $table->string('status_before_archive', 20)->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            UserKey::column($table, 'archived_by_id')->nullable();
            $table->string('status', 16)->default('moderated')->index();
            $table->timestamp('approved_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['plugin_id', 'version']);
            $table->unique(['plugin_id', 'internal_version']);
            $table->index(['plugin_id', 'status', 'created_at'], 'plugin_releases_plugin_status_created_idx');
            $table->index(['status', 'created_at'], 'plugin_releases_status_created_idx');
            $table->index(['created_at', 'id'], 'plugin_releases_created_at_id_idx');
            $table->index('latest_security_scan_id', 'plugin_releases_latest_security_scan_id_foreign');
            $table->index('quarantine_scan_id', 'plugin_releases_quarantine_scan_id_foreign');

            $table->foreign('plugin_id')->references('id')->on(Tables::name('plugins'))->cascadeOnDelete();
            $table->foreign('uploaded_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
            $table->foreign('quarantine_cleared_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
            $table->foreign('archived_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });

        Schema::create(Tables::name('plugin_reports'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('plugin_id');
            $table->unsignedBigInteger('plugin_release_id')->nullable();
            UserKey::column($table, 'reporter_id');
            $table->text('reason');
            $table->string('status', 16)->default('open')->index();
            UserKey::column($table, 'reviewed_by_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['plugin_id', 'status']);
            $table->index(['plugin_release_id', 'status']);
            $table->index(['reporter_id', 'created_at']);

            $table->foreign('plugin_id')->references('id')->on(Tables::name('plugins'))->cascadeOnDelete();
            $table->foreign('plugin_release_id')->references('id')->on(Tables::name('plugin_releases'))->nullOnDelete();
            $table->foreign('reporter_id')->references('id')->on(UserModel::table())->cascadeOnDelete();
            $table->foreign('reviewed_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });

        Schema::create(Tables::name('plugin_subscriptions'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('plugin_id');
            UserKey::column($table, 'user_id');
            $table->timestamps();

            $table->unique(['plugin_id', 'user_id']);
            $table->index(['user_id', 'created_at']);

            $table->foreign('plugin_id')->references('id')->on(Tables::name('plugins'))->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on(UserModel::table())->cascadeOnDelete();
        });

        Schema::create(Tables::name('plugin_moderator_notes'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('plugin_id');
            UserKey::column($table, 'moderator_id')->nullable();
            $table->text('note');
            $table->timestamps();

            $table->index(['plugin_id', 'created_at']);

            $table->foreign('plugin_id')->references('id')->on(Tables::name('plugins'))->cascadeOnDelete();
            $table->foreign('moderator_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('plugin_moderator_notes'));
        Schema::dropIfExists(Tables::name('plugin_subscriptions'));
        Schema::dropIfExists(Tables::name('plugin_reports'));
        Schema::dropIfExists(Tables::name('plugin_releases'));
        Schema::dropIfExists(Tables::name('plugins'));
        Schema::dropIfExists(Tables::name('plugin_types'));
    }
};
