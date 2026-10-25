<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach the network. The AI SDK talks to its provider through Laravel's HTTP
        // client, so an assistant call that is not faked fails the test instead of spending tokens.
        Http::preventStrayRequests();
    }
}
