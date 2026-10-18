<?php

declare(strict_types=1);

return [
    'submitted' => [
        'subject' => 'Plugin submission received: :plugin v:version',
        'intro' => 'Your submission of :plugin v:version has been received and is waiting for review.',
        'action' => 'View the plugin',
    ],
    'submitted_live' => [
        'subject' => 'Plugin published: :plugin v:version',
        'intro' => 'Your submission of :plugin v:version has been received and published.',
        'action' => 'View the plugin',
    ],
    'submitted_support' => [
        'subject' => 'Plugin submitted: :plugin v:version',
        'intro' => ':user submitted :plugin v:version.',
        'action' => 'View the plugin',
    ],
    'plugin_approved' => [
        'subject' => 'Plugin approved: :plugin',
        'intro' => 'Your plugin :plugin has been approved.',
        'action' => 'View the plugin',
    ],
    'plugin_rejected' => [
        'subject' => 'Plugin rejected: :plugin',
        'intro' => 'Your plugin :plugin was reviewed and rejected.',
        'action' => 'View the plugin',
    ],
    'release_approved' => [
        'subject' => 'Release approved: :plugin v:version',
        'intro' => 'Your release v:version of :plugin has been approved.',
        'action' => 'View the plugin',
    ],
    'release_rejected' => [
        'subject' => 'Release rejected: :plugin v:version',
        'intro' => 'Your release v:version of :plugin was reviewed and rejected.',
        'action' => 'View the plugin',
    ],
    'release_updated' => [
        'subject' => 'Plugin updated: :plugin v:version',
        'intro' => 'A new release of :plugin, v:version, is now available. You are receiving this because you follow the plugin.',
        'action' => 'View the plugin',
    ],
    'report_submitted' => [
        'subject' => 'Plugin reported: :plugin',
        'intro' => ':user reported :plugin.',
        'action' => 'View the plugin',
    ],
    'report_reviewed' => [
        'subject' => 'Your report on :plugin was reviewed',
        'intro' => 'Your report on :plugin is now: :status.',
        'action' => 'View the plugin',
    ],
];
