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

// Re-dispatches OCR work nobody is doing (a worker that died mid-read, a lost job) and fails
// receipts that have used all their attempts. Idempotent: it takes the right to dispatch with a
// conditional update and claim() decides, so repeats and overlaps are harmless. The overlap
// lock expires after 10 minutes so a crashed run cannot block recovery for the default day.
Schedule::command('receipts:recover')->everyMinute()->withoutOverlapping(10);

// Discards receipts nobody confirmed within the retention period and deletes their files.
// Idempotent (conditional updates under row locks), so repeats and overlaps are harmless.
Schedule::command('receipts:prune')->dailyAt('03:15')->withoutOverlapping();

// Keeps the failed_jobs log from growing for ever. It deletes only rows of that framework table
// (a log of failed queue payloads: IDs for the business jobs, and for the account-email notifications
// an encrypted payload) and never touches a business record; every side effect has its own tracking
// row, so nothing depends on these.
Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:30')->withoutOverlapping();
