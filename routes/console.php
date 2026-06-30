<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep monthly partitions ahead of the boundary so inserts never miss a partition.
Schedule::command('partitions:ensure')->monthlyOn(1, '00:30');

// Clean up expired, unaccepted team invitations.
Schedule::command('invitations:prune')->daily();
