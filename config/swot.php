<?php

return [
    'auto_refresh' => [
        'enabled' => env('SWOT_AUTO_REFRESH_ENABLED', true),
        'stale_hours' => env('SWOT_AUTO_REFRESH_STALE_HOURS', 1),
    ],
];
