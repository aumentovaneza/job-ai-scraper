<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case bindings
|--------------------------------------------------------------------------
|
| Feature tests get the full application TestCase and a fresh, migrated
| database per test (RefreshDatabase). Unit tests only get the base TestCase.
| Existing PHPUnit class-style tests (e.g. Auth/AuthTest) continue to run
| unchanged — Pest executes them via PHPUnit under the hood.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');
