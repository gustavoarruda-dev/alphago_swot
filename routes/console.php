<?php

use App\Http\Services\Swot\SwotAutoRefreshService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('swot:auto-refresh {--customer_uuid=} {--force}', function (SwotAutoRefreshService $service) {
    $customerUuid = trim((string) $this->option('customer_uuid'));
    $force = (bool) $this->option('force');

    if (! $force && ! (bool) config('swot.auto_refresh.enabled', true)) {
        $this->warn('SWOT auto-refresh is disabled by configuration.');

        return 0;
    }

    $summary = $service->run(
        customerUuid: $customerUuid !== '' ? $customerUuid : null,
        force: $force,
    );

    $this->info(sprintf(
        'SWOT auto-refresh finished: scanned=%d due=%d refreshed=%d skipped=%d failed=%d',
        $summary['scanned'],
        $summary['due'],
        $summary['refreshed'],
        $summary['skipped'],
        $summary['failed']
    ));

    foreach ($summary['errors'] as $error) {
        $this->error($error);
    }

    return $summary['failed'] > 0 ? 1 : 0;
})->purpose('Auto-refresh SWOT analyses when stale (>24h) or after approved source updates.');

Schedule::command('swot:auto-refresh')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->when(fn (): bool => (bool) config('swot.auto_refresh.enabled', true));
