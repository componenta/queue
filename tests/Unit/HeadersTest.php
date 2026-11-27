<?php

declare(strict_types=1);

namespace Componenta\Queue\Tests\Unit;

use Componenta\Queue\Exception\InvalidMessageException;
use Componenta\Queue\Headers;

it('snapshots referenced header inputs without retaining mutable aliases', function (): void {
    $value = 'original';
    $input = ['x-label' => &$value];
    $headers = new Headers($input);

    $value = 'changed';
    $input['x-added'] = 'later';
    $output = $headers->values;
    $output['x-label'] = 'changed through an output copy';

    expect($headers->values)->toBe(['x-label' => 'original']);
});

it('accepts empty and UTF-8 header values without normalizing them', function (): void {
    $values = ['x-empty' => '', 'x-text' => "e\u{0301} 🌍", 'componenta-internal' => 'value'];

    expect((new Headers($values))->values)->toBe($values)
        ->and((new Headers())->values)->toBe([]);
});

it('rejects invalid header names and values', function (array $values): void {
    expect(fn () => new Headers($values))->toThrow(InvalidMessageException::class);
})->with([
    'empty name' => [['' => 'value']],
    'numeric name' => [['123' => 'value']],
    'uppercase name' => [['X-Name' => 'value']],
    'leading underscore' => [['_name' => 'value']],
    'space in name' => [['x name' => 'value']],
    'trailing newline' => [["x-name\n" => 'value']],
    'non-ASCII name' => [['имя' => 'value']],
    'invalid UTF-8 value' => [['x-name' => "\xff"]],
    'integer value' => [['x-name' => 123]],
    'array value' => [['x-name' => ['nested']]],
    'object value' => [['x-name' => new \stdClass()]],
]);
