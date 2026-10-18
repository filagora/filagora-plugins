<?php

declare(strict_types=1);

/*
 * What the plugins table carries beyond this package's own snapshot when `filagora-forums` is
 * installed: the link from a plugin to its support thread. Register it with
 * SchemaExtension::fromFile().
 */

return [
    'tables' => [
        'plugins' => [
            'foreign_keys' => [
                'plugins_forum_thread_id_foreign' => [
                    'columns' => [
                        'forum_thread_id',
                    ],
                    'on' => 'threads',
                    'foreign_columns' => [
                        'id',
                    ],
                    'on_delete' => 'set null',
                    'on_update' => 'restrict',
                ],
            ],
        ],
    ],
];
