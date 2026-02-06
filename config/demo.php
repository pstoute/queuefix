<?php

return [
    'enabled' => env('QUEUEFIX_DEMO_MODE', false),
    'reset_interval' => (int) env('QUEUEFIX_DEMO_RESET_INTERVAL_MINUTES', 60),
    'github_url' => env('QUEUEFIX_DEMO_GITHUB_URL', 'https://github.com/yourusername/queuefix'),
];
