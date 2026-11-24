<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * The answers a school gives when it asks for an SMS price: people, texts and days.
     *
     * @var array{people: int, texts: list<string>, days: string}
     */
    protected const SMS_ANSWERS = ['people' => 30, 'texts' => ['time_in', 'time_out'], 'days' => 'weekdays'];

    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach a real outside service (for example Semaphore SMS); every HTTP call must be faked.
        Http::preventStrayRequests();
    }
}
