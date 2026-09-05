<?php

return [
    'poll_batch_size' => (int) env('INBOUND_EMAIL_POLL_BATCH_SIZE', 50),
    'claim_lease_seconds' => (int) env('INBOUND_EMAIL_CLAIM_LEASE_SECONDS', 900),
    'retry_base_seconds' => (int) env('INBOUND_EMAIL_RETRY_BASE_SECONDS', 300),
    'retry_max_seconds' => (int) env('INBOUND_EMAIL_RETRY_MAX_SECONDS', 3600),
    'max_failure_count' => (int) env('INBOUND_EMAIL_MAX_FAILURE_COUNT', 5),
];
