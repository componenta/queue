<?php

declare(strict_types=1);

namespace Componenta\Queue;

use Componenta\Queue\Exception\DecodingException;
use Componenta\Queue\Exception\InvalidMessageException;

interface MessageCodecInterface
{
    /** @throws InvalidMessageException */
    public function encode(Message $message): RawMessage;

    /** @throws DecodingException */
    public function decode(RawMessage $message): Message;
}
