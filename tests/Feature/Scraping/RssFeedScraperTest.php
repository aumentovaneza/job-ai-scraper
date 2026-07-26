<?php

use App\Jobs\DedupeJobJob;
use App\Jobs\ScrapeRssJob;
use App\Models\JobSource;
use App\Models\User;
use App\Services\RssFeedScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function rssSource(array $attributes = []): JobSource
{
    return JobSource::factory()->make(array_merge([
        'user_id' => User::factory()->create()->id,
        'type' => 'rss',
        'url' => 'https://jobs.test/feed.xml',
        'config' => [],
    ], $attributes));
}

it('parses an RSS 2.0 feed', function () {
    Http::fake([
        'jobs.test/*' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">
          <channel>
            <title>Acme Jobs</title>
            <item>
              <title>Backend Engineer (Remote)</title>
              <link>https://jobs.test/roles/7</link>
              <content:encoded>&lt;p&gt;Ship APIs.&lt;/p&gt;&lt;ul&gt;&lt;li&gt;Go&lt;/li&gt;&lt;/ul&gt;</content:encoded>
              <pubDate>Mon, 15 Jun 2026 10:00:00 +0000</pubDate>
            </item>
            <item>
              <title>Designer</title>
              <link>https://jobs.test/roles/8</link>
              <description>Make it pretty.</description>
            </item>
          </channel>
        </rss>
        XML),
    ]);

    $jobs = app(RssFeedScraper::class)->scrape(rssSource());

    expect($jobs)->toHaveCount(2);
    expect($jobs[0]->title)->toBe('Backend Engineer (Remote)');
    expect($jobs[0]->company)->toBe('Acme Jobs');
    expect($jobs[0]->remoteType)->toBe('remote');
    expect($jobs[0]->jdText)->toContain('Ship APIs');
    expect($jobs[0]->applyUrl)->toBe('https://jobs.test/roles/7');
    expect($jobs[0]->postedAt)->toStartWith('2026-06-15');
});

it('parses an Atom feed and prefers the alternate link', function () {
    Http::fake([
        'jobs.test/*' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <feed xmlns="http://www.w3.org/2005/Atom">
          <title>Atom Jobs</title>
          <entry>
            <title>Platform Engineer</title>
            <link rel="self" href="https://jobs.test/self"/>
            <link rel="alternate" href="https://jobs.test/roles/9"/>
            <content>Own the platform.</content>
            <updated>2026-07-02T00:00:00Z</updated>
          </entry>
        </feed>
        XML),
    ]);

    $jobs = app(RssFeedScraper::class)->scrape(rssSource());

    expect($jobs)->toHaveCount(1);
    expect($jobs[0]->title)->toBe('Platform Engineer');
    expect($jobs[0]->company)->toBe('Atom Jobs');
    expect($jobs[0]->applyUrl)->toBe('https://jobs.test/roles/9');
});

it('prefers a configured company over the feed title', function () {
    Http::fake([
        'jobs.test/*' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel><title>Generic Board</title>
          <item><title>Role</title><link>https://jobs.test/1</link></item>
        </channel></rss>
        XML),
    ]);

    $jobs = app(RssFeedScraper::class)->scrape(rssSource(['config' => ['company' => 'RealCo']]));

    expect($jobs[0]->company)->toBe('RealCo');
});

it('drops items without a title', function () {
    Http::fake([
        'jobs.test/*' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel><title>Board</title>
          <item><link>https://jobs.test/1</link><description>No title here.</description></item>
          <item><title>Real Role</title><link>https://jobs.test/2</link></item>
        </channel></rss>
        XML),
    ]);

    $jobs = app(RssFeedScraper::class)->scrape(rssSource());

    expect($jobs)->toHaveCount(1);
    expect($jobs[0]->title)->toBe('Real Role');
});

it('throws when the feed is not valid XML', function () {
    Http::fake(['jobs.test/*' => Http::response('<<not xml', 200)]);

    app(RssFeedScraper::class)->scrape(rssSource());
})->throws(RuntimeException::class, 'did not return valid XML');

it('throws when the feed returns an HTTP error', function () {
    Http::fake(['jobs.test/*' => Http::response('nope', 500)]);

    app(RssFeedScraper::class)->scrape(rssSource());
})->throws(RuntimeException::class, 'returned HTTP 500');

it('fans out a DedupeJobJob per posting and stamps the source', function () {
    Queue::fake();

    $source = JobSource::factory()->create([
        'user_id' => User::factory()->create()->id,
        'type' => 'rss',
        'url' => 'https://jobs.test/feed.xml',
        'active' => true,
    ]);

    Http::fake([
        'jobs.test/*' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel><title>Board</title>
          <item><title>Role A</title><link>https://jobs.test/1</link></item>
          <item><title>Role B</title><link>https://jobs.test/2</link></item>
        </channel></rss>
        XML),
    ]);

    (new ScrapeRssJob($source->id))->handle(app(RssFeedScraper::class));

    Queue::assertPushed(DedupeJobJob::class, 2);
    expect($source->fresh()->last_scraped_at)->not->toBeNull();
});
