<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Ties a plugin's support thread to the threads table of `filagora-forums`, clearing the link (not
 * deleting the plugin) when the thread goes. Dated after forums' own migrations. Does nothing when
 * forums is not installed, and is safe to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(Tables::name('threads')) || $this->hasForeignKey()) {
            return;
        }

        Schema::table(Tables::name('plugins'), function (Blueprint $table): void {
            $table->foreign('forum_thread_id')->references('id')->on(Tables::name('threads'))->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! $this->hasForeignKey()) {
            return;
        }

        Schema::table(Tables::name('plugins'), function (Blueprint $table): void {
            $table->dropForeign(['forum_thread_id']);
        });
    }

    private function hasForeignKey(): bool
    {
        foreach (Schema::getForeignKeys(Tables::name('plugins')) as $foreignKey) {
            if ($foreignKey['columns'] === ['forum_thread_id']) {
                return true;
            }
        }

        return false;
    }
};
