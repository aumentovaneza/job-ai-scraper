<?php

use App\Jobs\ScrapeAtsFeedJob;
use App\Jobs\ScrapeCareerPageJob;
use App\Jobs\ScrapeJsonApiJob;
use App\Models\JobSource;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function source(array $attributes): JobSource
{
    return JobSource::factory()->create(array_merge([
        'user_id' => User::factory()->create()->id,
    ], $attributes));
}

it('dispatches a job for a due ATS source', function () {
    Queue::fake();
    // "* * * * *" is due every minute.
    source(['type' => 'ats_feed', 'active' => true, 'cron_schedule' => '* * * * *',
        'config' => ['provider' => 'greenhouse', 'board_token' => 'a']]);

    $this->artisan('scrape:sources')->assertSuccessful();

    Queue::assertPushed(ScrapeAtsFeedJob::class, 1);
});

it('dispatches a job for a due json_api source', function () {
    Queue::fake();
    source(['type' => 'json_api', 'active' => true, 'cron_schedule' => '* * * * *',
        'url' => 'https://remoteok.com/api',
        'config' => ['field_map' => ['title' => 'position', 'company' => 'company']]]);

    $this->artisan('scrape:sources')->assertSuccessful();

    Queue::assertPushed(ScrapeJsonApiJob::class, 1);
});

it('skips a source whose cron is not due', function () {
    Queue::fake();
    // 31st of February never fires.
    source(['type' => 'ats_feed', 'active' => true, 'cron_schedule' => '0 0 31 2 *',
        'config' => ['provider' => 'greenhouse', 'board_token' => 'a']]);

    $this->artisan('scrape:sources')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('skips inactive sources', function () {
    Queue::fake();
    source(['type' => 'ats_feed', 'active' => false, 'cron_schedule' => '* * * * *',
        'config' => ['provider' => 'greenhouse', 'board_token' => 'a']]);

    $this->artisan('scrape:sources')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('force dispatches active sources ignoring cron', function () {
    Queue::fake();
    source(['type' => 'career_page', 'active' => true, 'cron_schedule' => null, 'url' => 'https://acme.test']);

    $this->artisan('scrape:sources --force')->assertSuccessful();

    Queue::assertPushed(ScrapeCareerPageJob::class, 1);
});

it('targets a single source with --source', function () {
    Queue::fake();
    $wanted = source(['type' => 'ats_feed', 'active' => true, 'cron_schedule' => null,
        'config' => ['provider' => 'lever', 'board_token' => 'a']]);
    source(['type' => 'ats_feed', 'active' => true, 'cron_schedule' => '* * * * *',
        'config' => ['provider' => 'lever', 'board_token' => 'b']]);

    $this->artisan("scrape:sources --source={$wanted->id}")->assertSuccessful();

    Queue::assertPushed(ScrapeAtsFeedJob::class, 1);
    Queue::assertPushed(fn (ScrapeAtsFeedJob $job) => $job->jobSourceId === $wanted->id);
});
