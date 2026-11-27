<?php

declare(strict_types=1);

namespace Componenta\Queue\Tests\Unit;

use Componenta\Queue\MessageLimits;

it('accepts large byte budgets without overflowing their configuration', function (): void {
    $limits = new MessageLimits(PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, 3);

    expect($limits->maxBodyBytes)->toBe(PHP_INT_MAX)
        ->and($limits->maxWireBytes)->toBe(PHP_INT_MAX)
        ->and($limits->maxHeaderBytes)->toBe(PHP_INT_MAX)
        ->and($limits->maxJsonDepth)->toBe(3);
});

it('rejects unusable message limits', function (int $body, int $wire, int $headers, int $depth): void {
    expect(fn () => new MessageLimits($body, $wire, $headers, $depth))->toThrow(\InvalidArgumentException::class);
})->with([
    'zero body budget' => [0, 1024, 512, 3],
    'negative body budget' => [-1, 1024, 512, 3],
    'zero wire budget' => [1024, 0, 512, 3],
    'negative wire budget' => [1024, -1, 512, 3],
    'zero header budget' => [1024, 1024, 0, 3],
    'negative header budget' => [1024, 1024, -1, 3],
    'insufficient JSON depth' => [1024, 1024, 512, 2],
    'unsupported native JSON depth' => [1024, 1024, 512, 2_147_483_648],
]);
