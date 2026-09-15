<?php

return [
    'version' => 'v1.1.2',
    'repository' => env('QUEUEFIX_UPDATE_REPOSITORY', 'pstoute/queuefix'),
    'cache_hours' => (int) env('QUEUEFIX_UPDATE_CACHE_HOURS', 12),
    'timeout_seconds' => (int) env('QUEUEFIX_UPDATE_TIMEOUT_SECONDS', 3),
];
