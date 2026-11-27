<?php

declare(strict_types=1);

namespace Componenta\Queue\Tests\Unit\Codec;

use Componenta\Queue\Codec\JsonMessageCodec;
use Componenta\Queue\Exception\DecodingException;
use Componenta\Queue\Exception\InvalidMessageException;
use Componenta\Queue\Headers;
use Componenta\Queue\Message;
use Componenta\Queue\MessageLimits;
use Componenta\Queue\MessageType;
use Componenta\Queue\RawMessage;

const WIRE_CONTENT_TYPE = 'application/vnd.componenta.queue+json;version=1';

function codec(?MessageLimits $limits = null): JsonMessageCodec
{
    return new JsonMessageCodec($limits ?? new MessageLimits(4096, 16384, 2048, 8));
}

function raw(string $body, ?Headers $headers = null): RawMessage
{
    return new RawMessage($body, $headers ?? new Headers(['content-type' => WIRE_CONTENT_TYPE]));
}

/** @param array<string,mixed> $changes */
function wire(array $changes = []): string
{
    return json_encode(array_replace([
        'format' => 'componenta.queue',
        'version' => 1,
        'id' => 'message-1',
        'type' => 'invoice.generate',
        'schemaVersion' => 1,
        'headers' => new \stdClass(),
        'bodyBase64' => 'AA==',
    ], $changes), JSON_THROW_ON_ERROR);
}

it('round-trips opaque bodies and application metadata exactly', function (string $body): void {
    $message = new Message('message-1', new MessageType('invoice.generate', 7), $body, new Headers([
        'x-unicode' => "e\u{0301} 🌍",
        'content-type' => 'application/octet-stream',
    ]));

    $encoded = codec()->encode($message);
    $decoded = codec()->decode($encoded);

    expect($decoded->id)->toBe($message->id)
        ->and($decoded->type->name)->toBe('invoice.generate')
        ->and($decoded->type->schemaVersion)->toBe(7)
        ->and($decoded->body)->toBe($body)
        ->and($decoded->headers->values)->toEqual($message->headers->values)
        ->and($encoded->headers->values)->toBe(['content-type' => WIRE_CONTENT_TYPE]);
})->with([
    'empty' => [''],
    'ASCII' => ['hello'],
    'UTF-8' => ['Привет 🌍'],
    'binary' => ["\0\x01\xff\xfe"],
    'one byte' => ["\xff"],
    'two bytes' => ["\0\xff"],
    'complete three-byte group' => ['abc'],
]);

it('encodes the declared key order and bytewise-sorted headers deterministically', function (): void {
    $message = new Message('message-1', new MessageType('invoice.generate', 1), "\0\xff", new Headers([
        'x-z' => 'last',
        'x-a' => 'first',
    ]));
    $reordered = new Message('message-1', $message->type, $message->body, new Headers([
        'x-a' => 'first',
        'x-z' => 'last',
    ]));

    $encoded = codec()->encode($message);

    expect($encoded->body)->toBe('{"format":"componenta.queue","version":1,"id":"message-1","type":"invoice.generate","schemaVersion":1,"headers":{"x-a":"first","x-z":"last"},"bodyBase64":"AP8="}')
        ->and(codec()->encode($reordered)->body)->toBe($encoded->body);
});

it('encodes empty headers as an object and accepts permitted whitespace and member order', function (): void {
    $encoded = codec()->encode(new Message('message-1', new MessageType('invoice.generate', 1), ''));
    $reordered = raw(' { "bodyBase64": "", "headers": {}, "schemaVersion": 1, "type": "invoice.generate", "id": "message-1", "version": 1, "format": "componenta.queue" } ');

    expect(json_decode($encoded->body, false, 512, JSON_THROW_ON_ERROR)->headers)->toBeInstanceOf(\stdClass::class)
        ->and(codec()->decode($reordered)->body)->toBe('');
});

it('accepts uniquely escaped member names and delimiter-rich string values', function (): void {
    $value = 'quoted " text, braces {}[] and slash \\';
    $body = wire(['headers' => (object) ['x-value' => $value]]);
    $body = str_replace('"id":', '"\\u0069d":', $body);
    $body = str_replace('"x-value":', '"\\u0078-value":', $body);

    $decoded = codec()->decode(raw($body));

    expect($decoded->id)->toBe('message-1')
        ->and($decoded->headers->values)->toBe(['x-value' => $value]);
});

it('requires the exact wire content type independently of application headers', function (array $rawHeaders): void {
    $encoded = raw(wire(['headers' => (object) ['content-type' => WIRE_CONTENT_TYPE]]), new Headers($rawHeaders));

    expect(fn () => codec()->decode($encoded))->toThrow(DecodingException::class);
})->with([
    'absent' => [[]],
    'generic JSON' => [['content-type' => 'application/json']],
    'different version' => [['content-type' => 'application/vnd.componenta.queue+json;version=2']],
]);

it('does not merge provider headers into application headers', function (): void {
    $encoded = raw(wire(['headers' => (object) ['x-app' => 'application']]), new Headers([
        'content-type' => WIRE_CONTENT_TYPE,
        'x-provider' => 'transport',
    ]));

    expect(codec()->decode($encoded)->headers->values)->toBe(['x-app' => 'application']);
});

it('rejects malformed and unsupported envelopes through the decoding boundary', function (string $body): void {
    expect(fn () => codec()->decode(raw($body)))->toThrow(DecodingException::class);
})->with([
    'root null' => ['null'],
    'root array' => ['[]'],
    'root scalar' => ['1'],
    'missing fields' => ['{}'],
    'unknown field' => [wire(['unknown' => true])],
    'unsupported format' => [wire(['format' => 'other'])],
    'unsupported wire version' => [wire(['version' => 2])],
    'string wire version' => [wire(['version' => '1'])],
    'float wire version' => [str_replace('"version":1', '"version":1.0', wire())],
    'overflow wire version' => [str_replace('"version":1', '"version":9223372036854775808', wire())],
    'null ID' => [wire(['id' => null])],
    'empty ID' => [wire(['id' => ''])],
    'control ID' => [wire(['id' => "message\u{0085}"])],
    'invalid UTF-8' => [str_replace('message-1', "message\xff", wire())],
    'invalid alias' => [wire(['type' => 'App\\Executable'])],
    'nonpositive schema' => [wire(['schemaVersion' => 0])],
    'string schema' => [wire(['schemaVersion' => '1'])],
    'float schema' => [str_replace('"schemaVersion":1', '"schemaVersion":1.0', wire())],
    'headers array' => [wire(['headers' => []])],
    'headers null' => [wire(['headers' => null])],
    'numeric-only header name' => [wire(['headers' => (object) ['123' => 'value']])],
    'non-string header value' => [wire(['headers' => (object) ['x-value' => 123]])],
    'library header in application map' => [wire(['headers' => (object) ['componenta-private' => 'value']])],
    'non-string body' => [wire(['bodyBase64' => null])],
    'root duplicate' => [str_replace('"id":"message-1"', '"id":"first","id":"message-1"', wire())],
    'escaped root duplicate' => [str_replace('"id":"message-1"', '"\\u0069d":"first","id":"message-1"', wire())],
    'nested header duplicate' => [str_replace('"headers":{}', '"headers":{"x-name":"first","x-name":"second"}', wire())],
    'escaped nested header duplicate' => [str_replace('"headers":{}', '"headers":{"x-name":"first","\\u0078-name":"second"}', wire())],
    'deep malformed object' => [str_replace('"headers":{}', '"headers":{"x":'.str_repeat('[', 20).'0'.str_repeat(']', 20).'}', wire())],
    'invalid JSON syntax' => ['{"format":'],
    'trailing input' => [wire().' true'],
]);

it('accepts a known business type with the largest integer schema without narrowing it', function (): void {
    $decoded = codec()->decode(raw(wire(['schemaVersion' => PHP_INT_MAX])));

    expect($decoded->type->schemaVersion)->toBe(PHP_INT_MAX);
});

it('rejects noncanonical or invalid base64 rather than changing the payload', function (string $base64): void {
    expect(fn () => codec()->decode(raw(wire(['bodyBase64' => $base64]))))->toThrow(DecodingException::class);
})->with([
    'missing padding' => ['AA'],
    'partial padding' => ['AA='],
    'excess padding' => ['AA==='],
    'embedded whitespace' => ['A A=='],
    'trailing newline' => ["AA==\n"],
    'nonzero padding bits' => ['AB=='],
    'invalid length' => ['A==='],
    'URL-safe alphabet' => ['_w=='],
    'padding in middle' => ['AA==AA=='],
    'padding only' => ['='],
    'invalid alphabet' => ['@@@@'],
]);

it('enforces body byte limits symmetrically at the boundary', function (): void {
    $message = new Message('message-1', new MessageType('invoice.generate', 1), "\0\xff");
    $encoded = raw(wire(['bodyBase64' => 'AP8=']));
    $accepted = codec(new MessageLimits(2, 4096, 512, 3));
    $rejected = codec(new MessageLimits(1, 4096, 512, 3));

    expect($accepted->decode($accepted->encode($message))->body)->toBe("\0\xff")
        ->and(fn () => $rejected->encode($message))->toThrow(InvalidMessageException::class)
        ->and(fn () => $rejected->decode($encoded))->toThrow(DecodingException::class);
});

it('counts raw headers as part of the complete wire byte limit', function (): void {
    $message = new Message('message-1', new MessageType('invoice.generate', 1), "\0");
    $encoded = raw(wire());
    $size = strlen($encoded->body) + strlen('content-type') + strlen(WIRE_CONTENT_TYPE);
    $accepted = codec(new MessageLimits(1, $size, 512, 3));
    $rejected = codec(new MessageLimits(1, $size - 1, 512, 3));

    expect($accepted->encode($message)->body)->toBe($encoded->body)
        ->and($accepted->decode($encoded)->body)->toBe("\0")
        ->and(fn () => $rejected->encode($message))->toThrow(InvalidMessageException::class)
        ->and(fn () => $rejected->decode($encoded))->toThrow(DecodingException::class);
});

it('bounds application headers independently of the provider header map', function (): void {
    $headers = new Headers(['x-text' => str_repeat('a', 100)]);
    $message = new Message('message-1', new MessageType('invoice.generate', 1), '', $headers);
    $size = strlen('x-text') + 100;
    $accepted = codec(new MessageLimits(1, 4096, $size, 3));
    $rejected = codec(new MessageLimits(1, 4096, $size - 1, 3));
    $encoded = raw(wire(['headers' => (object) $headers->values, 'bodyBase64' => '']));

    expect($accepted->decode($accepted->encode($message))->headers->values)->toBe($headers->values)
        ->and(fn () => $rejected->encode($message))->toThrow(InvalidMessageException::class)
        ->and(fn () => $rejected->decode($encoded))->toThrow(DecodingException::class);
});

it('bounds provider headers independently even when application headers are empty', function (): void {
    $headers = new Headers(['content-type' => WIRE_CONTENT_TYPE, 'x-provider' => str_repeat('a', 100)]);
    $size = strlen('content-type') + strlen(WIRE_CONTENT_TYPE) + strlen('x-provider') + 100;
    $encoded = raw(wire(), $headers);

    expect(codec(new MessageLimits(1, 4096, $size, 3))->decode($encoded)->headers->values)->toBe([])
        ->and(fn () => codec(new MessageLimits(1, 4096, $size - 1, 3))->decode($encoded))
        ->toThrow(DecodingException::class);
});

it('does not overflow calculations for large configured byte budgets', function (): void {
    $codec = codec(new MessageLimits(PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, 3));
    $message = new Message('message-1', new MessageType('invoice.generate', 1), 'ordinary payload');

    expect($codec->decode($codec->encode($message))->body)->toBe('ordinary payload');
});
