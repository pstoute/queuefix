<?php

return [
    'disk' => env('ATTACHMENT_DISK', 'private'),

    'max_files_per_message' => (int) env('ATTACHMENT_MAX_FILES', 10),
    'max_file_bytes' => (int) env('ATTACHMENT_MAX_FILE_BYTES', 10 * 1024 * 1024),
    'max_message_bytes' => (int) env('ATTACHMENT_MAX_MESSAGE_BYTES', 25 * 1024 * 1024),
    'max_mailbox_bytes' => (int) env('ATTACHMENT_MAX_MAILBOX_BYTES', 5 * 1024 * 1024 * 1024),
    'max_installation_bytes' => (int) env('ATTACHMENT_MAX_INSTALLATION_BYTES', 25 * 1024 * 1024 * 1024),
    'max_office_entries' => 5000,
    'max_office_uncompressed_bytes' => 100 * 1024 * 1024,
    'max_office_compression_ratio' => 100,

    /*
    | When scanning is required, the default scanner deliberately leaves files
    | pending. Pending files are stored privately but cannot be downloaded until
    | a configured scanner marks them clean.
    */
    'scanning_required' => (bool) env('ATTACHMENT_SCANNING_REQUIRED', true),

    /*
    | Archive formats, macro-enabled Office files, SVG, HTML, and executables are
    | intentionally absent. Office Open XML files are inspected as containers.
    */
    'allowed_extensions' => [
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'docx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'pptx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    ],
];
