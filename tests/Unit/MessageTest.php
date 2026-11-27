<?php

declare(strict_types=1);

namespace Componenta\Queue\Tests\Unit;

use Componenta\Queue\Exception\InvalidMessageException;
use Componenta\Queue\Headers;
use Componenta\Queue\Message;
use Componenta\Queue\MessageType;
use Componenta\Queue\RawMessage;

it('preserves caller identity and opaque binary message bytes', function (): void {
    $message = new Message("task/e\u{0301}", new MessageType('invoice.generate', 1), "\x00\xff");

    expect($message->id)->toBe("task/e\u{0301}")
        ->and($message->body)->toBe("\x00\xff")
        ->and($message->headers->values)->toBe([]);
});

it('rejects empty, non-UTF-8 and control-containing message IDs', function (string $id): void {
    expect(fn () => new Message($id, new MessageType('invoice.generate', 1), ''))
        ->toThrow(InvalidMessageException::class);
})->with([
    'empty' => [''],
    'newline' => ["message\n"],
    'NUL' => ["message\0"],
    'DEL' => ["message\x7f"],
    'Unicode control' => ["message\u{0085}"],
    'invalid UTF-8' => ["message\xff"],
]);

it('reserves library headers only at the application message boundary', function (): void {
    $headers = new Headers(['componenta-private' => 'value']);
    $raw = new RawMessage("not JSON\xff", $headers);

    expect($raw->body)->toBe("not JSON\xff")
        ->and($raw->headers->values)->toBe(['componenta-private' => 'value'])
        ->and(fn () => new Message('message-1', new MessageType('invoice.generate', 1), '', $headers))
        ->toThrow(InvalidMessageException::class);
});
