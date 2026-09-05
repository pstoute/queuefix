<?php

return [
    'auto_watch' => [
        'creator' => env('TICKET_AUTO_WATCH_CREATOR', false),
        'assignee' => env('TICKET_AUTO_WATCH_ASSIGNEE', false),
    ],
];
