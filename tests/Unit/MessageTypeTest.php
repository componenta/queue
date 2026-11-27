<?php

declare(strict_types=1);

namespace Componenta\Queue\Tests\Unit;

use Componenta\Queue\Exception\InvalidMessageException;
use Componenta\Queue\MessageType;

it('accepts stable aliases and positive schema versions', function (string $name): void {
    $type = new MessageType($name, PHP_INT_MAX);

    expect($type->name)->toBe($name)->and($type->schemaVersion)->toBe(PHP_INT_MAX);
})->with(['a', 'invoice.generate', 'send-email', 'v1_payload']);

it('rejects names that are not stable application aliases', function (string $name): void {
    expect(fn () => new MessageType($name, 1))->toThrow(InvalidMessageException::class);
})->with(['', 'Invoice.generate', 'a..b', '-invoice', 'invoice.', 'with space', "invoice\n", 'счёт']);

it('rejects nonpositive schema versions', function (int $version): void {
    expect(fn () => new MessageType('invoice.generate', $version))->toThrow(InvalidMessageException::class);
})->with([0, -1]);
