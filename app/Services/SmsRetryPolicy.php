<?php

namespace App\Services;

use Carbon\CarbonInterface;

class SmsRetryPolicy
{
    public function nextAttemptAt(int $attemptCount, CarbonInterface $now, ?int $retryAfterSeconds = null): CarbonInterface
    {
        $delays = config('services.semaphore.retry_delays', [60, 300]);

        return $now->copy()->addSeconds((int) ($retryAfterSeconds ?? ($delays[$attemptCount - 1] ?? end($delays))));
    }
}
