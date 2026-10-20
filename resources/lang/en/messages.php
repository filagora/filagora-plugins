<?php

declare(strict_types=1);

return [
    'navigation_groups' => ['plugins' => 'Plugins'],

    'note' => 'Note',
    'done' => 'Done',
    'change_status' => 'Change status',

    'moderation' => [
        'approve' => 'Approve',
        'reject' => 'Reject',
    ],

    'plugins' => [
        'navigation_label' => 'Plugins',
        'model_label' => 'plugin',
        'plural_model_label' => 'plugins',
        'type' => 'Type',
        'owner' => 'Owner',
        'releases' => 'Releases',
        'support_thread' => 'Support thread ID',
        'github' => 'GitHub repository',
    ],

    'releases' => [
        'navigation_label' => 'Plugin releases',
        'model_label' => 'release',
        'plural_model_label' => 'releases',
        'plugin' => 'Plugin',
        'uploader' => 'Uploader',
        'file' => 'File',
        'download' => 'Download',
        'archive' => 'Archive (drop from the scan sweep)',
        'archive_help' => "Archived releases stay listed and downloadable but leave the security sweep. A plugin's newest active release is never archived.",
        'archived' => 'Archived :count release(s)',
        'kept_back' => "Kept back :count: a plugin's last active release is never archived.",
        'restore' => 'Restore from archive',
        'restored' => 'Restored :count release(s)',
    ],

    'types' => [
        'navigation_label' => 'Plugin types',
        'model_label' => 'plugin type',
        'plural_model_label' => 'plugin types',
        'scope' => 'Download needs',
        'scope_help' => 'The entitlement a member needs to see and download plugins of this type. Leave empty for everyone.',
        'plugins' => 'Plugins',
    ],

    'reports' => [
        'navigation_label' => 'Plugin reports',
        'model_label' => 'report',
        'plural_model_label' => 'reports',
        'plugin' => 'Plugin',
        'release' => 'Release',
        'reporter' => 'Reporter',
        'mark_reviewed' => 'Mark reviewed',
        'dismiss' => 'Dismiss',
    ],

    'notes' => [
        'title' => 'Staff notes',
        'moderator' => 'By',
        'add' => 'Add a note',
    ],
];
