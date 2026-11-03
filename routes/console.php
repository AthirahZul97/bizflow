<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recurring invoices become due by date, so running hourly only shortens recovery
// after downtime; it never creates extra invoices. Overlapping or repeated runs
// are safe (row lock + unique occurrence key); withoutOverlapping just avoids
// wasted work.
Schedule::command('invoices:generate-recurring')->hourlyAt(5)->withoutOverlapping();

// Discards receipts nobody confirmed within the retention period and deletes their files.
// Idempotent (conditional updates under row locks), so repeats and overlaps are harmless.
Schedule::command('receipts:prune')->dailyAt('03:15')->withoutOverlapping();
