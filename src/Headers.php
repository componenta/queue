<?php

declare(strict_types=1);

namespace Componenta\Queue;

use Componenta\Queue\Exception\InvalidMessageException;

final readonly class Headers
{
    /** @var array<string,string> */
    public array $values;

    /** @param array<array-key,mixed> $values Validated before becoming the immutable string map. */
    public function __construct(array $values = [])
    {
        $snapshot = [];

        foreach ($values as $name => $value) {
            if (!is_string($name) || preg_match('/\A[a-z][a-z0-9_-]*\z/', $name) !== 1) {
                throw new InvalidMessageException('Header names must be lower-case ASCII identifiers starting with a letter.');
            }

            if (!is_string($value) || preg_match('//u', $value) !== 1) {
                throw new InvalidMessageException('Header values must be valid UTF-8 strings.');
            }

            $snapshot[$name] = $value;
        }

        $this->values = $snapshot;
    }
}
