<?php

use App\Exceptions\VoyageException;
use App\Services\VoyageClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.voyage.key', 'test-key');
    config()->set('services.voyage.base_url', 'https://api.voyageai.com/v1');
    config()->set('services.voyage.dimensions', 4);
});

it('embeds text and returns the vector', function () {
    Http::fake([
        'api.voyageai.com/*' => Http::response([
            'data' => [['index' => 0, 'embedding' => [0.1, 0.2, 0.3, 0.4]]],
        ]),
    ]);

    $vector = app(VoyageClient::class)->embed('hello world');

    expect($vector)->toBe([0.1, 0.2, 0.3, 0.4]);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-key')
        && $request['input'] === ['hello world']
        && $request['output_dimension'] === 4);
});

it('preserves order across a batch', function () {
    Http::fake([
        'api.voyageai.com/*' => Http::response([
            'data' => [
                ['index' => 1, 'embedding' => [9, 9]],
                ['index' => 0, 'embedding' => [1, 1]],
            ],
        ]),
    ]);

    $vectors = app(VoyageClient::class)->embedBatch(['a', 'b']);

    expect($vectors[0])->toBe([1.0, 1.0]);
    expect($vectors[1])->toBe([9.0, 9.0]);
});

it('returns an empty array for empty input', function () {
    expect(app(VoyageClient::class)->embedBatch([]))->toBe([]);
});

it('throws when the key is missing', function () {
    config()->set('services.voyage.key', null);

    app(VoyageClient::class)->embed('x');
})->throws(VoyageException::class);

it('throws on an error response', function () {
    Http::fake(['api.voyageai.com/*' => Http::response('nope', 429)]);

    app(VoyageClient::class)->embed('x');
})->throws(VoyageException::class);
