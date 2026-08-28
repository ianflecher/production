<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Finished jobs put their pictures away by themselves.
 *
 * Sixty days after an order is completed it is delivered, paid and settled.
 * Its reference photos and payment slips are never opened again, and they are
 * the weight on this machine: the whole database is under three megabytes,
 * the uploads are nearly four hundred.
 *
 * The full original is copied to the archive in OneDrive first — one folder
 * per order number, openable in Explorer when a client argues about a payment
 * — and only then is the copy on this machine replaced by a smaller one.
 * Nothing is deleted and nothing is lost.
 *
 * Nightly at half past one, when nobody is on the floor: it reads every
 * picture of every long-finished job, which is not work to do while the shop
 * is running. withoutOverlapping so a long night cannot start a second pass on
 * top of the first.
 */
Schedule::command('images:shrink-completed --days=60 --apply')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/archive-completed.log'));
