<?php
return [
    'token' => env('BSALE_TOKEN'),
    'base_url' => env('BSALE_BASE_URL'),
    'timeout' => (int) env('BSALE_TIMEOUT', 15),
    'batch_size' => (int) env('BSALE_BATCH_SIZE', 50),
    'state' => env('BSALE_DOCUMENT_STATE', 0),
    'sync_overlap_minutes' => (int) env('BSALE_SYNC_OVERLAP_MINUTES', 5),
    'bootstrap_lookback_minutes' => (int) env('BSALE_SYNC_BOOTSTRAP_LOOKBACK_MINUTES', 1440),
    'stale_after_minutes' => (int) env('BSALE_SYNC_STALE_AFTER_MINUTES', 30),
];