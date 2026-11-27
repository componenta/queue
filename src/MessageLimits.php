<?php

declare(strict_types=1);

namespace Componenta\Queue;

final readonly class MessageLimits
{
    /** @var positive-int */
    public int $maxBodyBytes;

    /** @var positive-int */
    public int $maxWireBytes;

    /** @var positive-int */
    public int $maxHeaderBytes;

    /** @var int<3,2147483647> */
    public int $maxJsonDepth;

    public function __construct(
        int $maxBodyBytes,
        int $maxWireBytes,
        int $maxHeaderBytes,
        int $maxJsonDepth,
    ) {
        if ($maxBodyBytes < 1 || $maxWireBytes < 1 || $maxHeaderBytes < 1) {
            throw new \InvalidArgumentException('Message byte limits must be positive integers.');
        }

        if ($maxJsonDepth < 3 || $maxJsonDepth > 2_147_483_647) {
            throw new \InvalidArgumentException('JSON depth must be between 3 and 2147483647.');
        }

        $this->maxBodyBytes = $maxBodyBytes;
        $this->maxWireBytes = $maxWireBytes;
        $this->maxHeaderBytes = $maxHeaderBytes;
        $this->maxJsonDepth = $maxJsonDepth;
    }
}
