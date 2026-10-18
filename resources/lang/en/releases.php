<?php

declare(strict_types=1);

return [
    'refusals' => [
        'submissions_paused' => 'Plugin submissions are temporarily paused. Please try again later.',
        'invalid_version' => 'Enter a version such as 1.0.1: two to four numbers separated by dots, with an optional leading v.',
        'duplicate_version' => 'This plugin already has a release with that version.',
        'pending_releases' => 'Releases of this plugin are still waiting for review.',
        'unknown_type' => 'Choose a plugin type from the list.',
        'not_an_archive' => 'The uploaded file is not a readable ZIP archive.',
        'too_many_files' => 'The uploaded ZIP archive contains too many files (:count) to process safely.',
        'too_large' => 'The uploaded ZIP archive is too large once decompressed and was rejected.',
        'unsafe_path' => 'The uploaded ZIP archive contains an unsafe path (":path") and was rejected.',
        'whitespace_in_path' => 'The uploaded ZIP archive contains a path with a space (":path"). Rename it to use underscores or hyphens and upload it again.',
        'rejected_by_extension' => 'The release was refused.',
    ],

    'supersede' => [
        'required' => 'Version :versions of this plugin is still waiting for review. Uploading a new release rejects it permanently. Confirm to continue.|Versions :versions of this plugin are still waiting for review. Uploading a new release rejects them permanently. Confirm to continue.',
        'note' => 'Replaced by v:version, uploaded by :user.',
    ],
];
