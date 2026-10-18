<?php

declare(strict_types=1);

return [
    'refusals' => [
        'not_permitted' => 'You are not allowed to moderate plugins.',
        'note_required' => 'Say why in a note before rejecting something for good.',
        'release_mismatch' => 'That release does not belong to this plugin.',
        'already_reported' => 'You have already reported this plugin.',
        'already_reviewed' => 'This report has already been reviewed.',
    ],

    'notes' => [
        'plugin_approved' => 'Plugin approved.',
        'plugin_rejected' => 'Plugin rejected.',
        'release_approved' => 'Release v:version approved.',
        'release_rejected' => 'Release v:version rejected.',
        'release_superseded' => 'Release v:version replaced by a newer upload.',
    ],

    'defaults' => [
        'author' => 'the author',
    ],
];
