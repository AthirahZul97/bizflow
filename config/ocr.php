<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Receipt OCR provider
    |--------------------------------------------------------------------------
    |
    | "fake" returns the same demo data for every receipt and reads nothing from the file:
    | it exists for development and tests, and every page that shows its output says so.
    | "none" turns receipt scanning off. No real OCR provider has been selected or integrated,
    | and no receipt leaves this application.
    |
    */

    'driver' => env('OCR_DRIVER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | The largest accepted file and image size. PHP's own upload_max_filesize and
    | post_max_size apply first, so they must be at least as large as max_upload_kb.
    |
    */

    'max_upload_kb' => 8192,

    'max_image_pixels' => 40_000_000,

    /*
    |--------------------------------------------------------------------------
    | Processing
    |--------------------------------------------------------------------------
    |
    | max_attempts is the most times one receipt may be sent to the provider, automatic
    | retries and the user's Retry together. A "processing" row older than
    | stale_processing_seconds is an attempt whose worker died: it may be reclaimed. Keep it
    | above the job's timeout.
    |
    | receipts:recover (every minute) is what reclaims such a row: the queue alone cannot,
    | because a job redelivered inside the stale window finds the claim still live and ends.
    | redispatch_after_seconds is how long a row must have been idle before the sweeper
    | dispatches it again (a stale "processing" row, or a "queued" row whose job was lost); it
    | also throttles repeat dispatches. Keep it at least as long as the job's longest backoff so
    | the sweeper does not race a job the queue is about to release. recovery_batch caps the rows
    | one run handles.
    |
    */

    'max_attempts' => 5,

    'stale_processing_seconds' => 180,

    'redispatch_after_seconds' => 180,

    'recovery_batch' => 100,

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Receipts nobody confirmed are discarded, and their files deleted, after this many days
    | (receipts:prune). Confirmed receipts keep their file with the expense.
    |
    */

    'prune_after_days' => 30,

];
