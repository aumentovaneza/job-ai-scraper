<?php

use App\Support\NormalizedJob;

it('derives a stable source hash from company, title and location', function () {
    $job = new NormalizedJob(title: 'Senior Go Engineer', company: 'Acme', location: 'Remote');

    expect($job->sourceHash())->toBe($job->sourceHash())
        ->and($job->sourceHash())->toHaveLength(64); // sha256 hex
});

it('normalizes case and whitespace when hashing', function () {
    $a = new NormalizedJob(title: 'Senior Go Engineer', company: 'Acme', location: 'Remote');
    $b = new NormalizedJob(title: '  senior   GO   engineer ', company: 'ACME', location: 'remote');

    expect($a->sourceHash())->toBe($b->sourceHash());
});

it('distinguishes different roles', function () {
    $a = new NormalizedJob(title: 'Senior Go Engineer', company: 'Acme', location: 'Remote');
    $b = new NormalizedJob(title: 'Junior Go Engineer', company: 'Acme', location: 'Remote');

    expect($a->sourceHash())->not->toBe($b->sourceHash());
});

it('round-trips through array form', function () {
    $job = new NormalizedJob(
        title: 'Engineer',
        company: 'Acme',
        location: 'Berlin',
        remoteType: 'hybrid',
        salaryMin: 80000,
        salaryMax: 120000,
        salaryCurrency: 'EUR',
        jdText: 'Build.',
        applyUrl: 'https://acme.test/1',
        postedAt: '2026-06-01T00:00:00+00:00',
        sourceUrl: 'https://acme.test/1',
    );

    $restored = NormalizedJob::fromArray($job->toArray());

    expect($restored->toArray())->toBe($job->toArray());
    expect($restored->sourceHash())->toBe($job->sourceHash());
});
