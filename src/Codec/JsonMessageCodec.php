<?php

declare(strict_types=1);

namespace Componenta\Queue\Codec;

use Componenta\Queue\Exception\DecodingException;
use Componenta\Queue\Exception\InvalidMessageException;
use Componenta\Queue\Headers;
use Componenta\Queue\Message;
use Componenta\Queue\MessageCodecInterface;
use Componenta\Queue\MessageLimits;
use Componenta\Queue\MessageType;
use Componenta\Queue\RawMessage;
use JsonException;
use stdClass;

final readonly class JsonMessageCodec implements MessageCodecInterface
{
    private const string CONTENT_TYPE = 'application/vnd.componenta.queue+json;version=1';

    private const array FIELDS = ['format', 'version', 'id', 'type', 'schemaVersion', 'headers', 'bodyBase64'];

    public function __construct(private MessageLimits $limits)
    {
    }

    #[\Override]
    public function encode(Message $message): RawMessage
    {
        $bodyLength = strlen($message->body);

        if ($bodyLength > $this->limits->maxBodyBytes) {
            throw new InvalidMessageException('Message body exceeds the configured byte limit.');
        }

        $this->headerBytes($message->headers);
        $rawHeaders = new Headers(['content-type' => self::CONTENT_TYPE]);
        $headerBytes = $this->headerBytes($rawHeaders);
        $wireBudget = $this->remainingWireBudget($headerBytes);
        $base64Groups = intdiv($bodyLength, 3) + ($bodyLength % 3 === 0 ? 0 : 1);

        if ($base64Groups > intdiv($wireBudget, 4)) {
            throw new InvalidMessageException('Encoded body exceeds the configured wire byte limit.');
        }

        $headers = $message->headers->values;
        ksort($headers, SORT_STRING);

        try {
            $body = json_encode([
                'format' => 'componenta.queue',
                'version' => 1,
                'id' => $message->id,
                'type' => $message->type->name,
                'schemaVersion' => $message->type->schemaVersion,
                'headers' => (object) $headers,
                'bodyBase64' => base64_encode($message->body),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, $this->limits->maxJsonDepth);
        } catch (JsonException $error) {
            throw new InvalidMessageException('Message could not be encoded as JSON.', previous: $error);
        }

        if (strlen($body) > $wireBudget) {
            throw new InvalidMessageException('Message exceeds the configured complete wire byte limit.');
        }

        return new RawMessage($body, $rawHeaders);
    }

    #[\Override]
    public function decode(RawMessage $message): Message
    {
        try {
            $headerBytes = $this->headerBytes($message->headers);

            if (strlen($message->body) > $this->remainingWireBudget($headerBytes)) {
                throw new DecodingException('Message exceeds the configured complete wire byte limit.');
            }

            if (($message->headers->values['content-type'] ?? null) !== self::CONTENT_TYPE) {
                throw new DecodingException('The required queue wire content type is missing or unsupported.');
            }

            if (!json_validate($message->body, $this->limits->maxJsonDepth)) {
                throw new JsonException(json_last_error_msg(), json_last_error());
            }

            $this->rejectDuplicateMemberNames($message->body);
            $envelope = json_decode($message->body, false, $this->limits->maxJsonDepth, JSON_THROW_ON_ERROR);
            $this->validateEnvelope($envelope);

            $headers = new Headers(get_object_vars($envelope->headers));
            $this->headerBytes($headers);

            return new Message(
                $envelope->id,
                new MessageType($envelope->type, $envelope->schemaVersion),
                $this->decodeBody($envelope->bodyBase64),
                $headers,
            );
        } catch (InvalidMessageException | JsonException $error) {
            throw new DecodingException('Invalid queue message encoding.', previous: $error);
        }
    }

    private function headerBytes(Headers $headers): int
    {
        $remaining = $this->limits->maxHeaderBytes;

        foreach ($headers->values as $name => $value) {
            foreach ([$name, $value] as $part) {
                $length = strlen($part);

                if ($length > $remaining) {
                    throw new InvalidMessageException('Headers exceed the configured byte limit.');
                }

                $remaining -= $length;
            }
        }

        return $this->limits->maxHeaderBytes - $remaining;
    }

    private function remainingWireBudget(int $headerBytes): int
    {
        if ($headerBytes > $this->limits->maxWireBytes) {
            throw new InvalidMessageException('Headers exceed the configured complete wire byte limit.');
        }

        return $this->limits->maxWireBytes - $headerBytes;
    }

    /**
     * @phpstan-assert stdClass&object{
     *     format: 'componenta.queue', version: 1, id: string, type: string,
     *     schemaVersion: int, headers: stdClass, bodyBase64: string
     * } $envelope
     */
    private function validateEnvelope(mixed $envelope): void
    {
        if (!$envelope instanceof stdClass) {
            throw new DecodingException('A queue envelope must be a JSON object.');
        }

        $fields = array_keys(get_object_vars($envelope));

        if (count($fields) !== count(self::FIELDS) || array_diff($fields, self::FIELDS) !== []) {
            throw new DecodingException('The queue envelope contains missing or unknown fields.');
        }

        if ($envelope->format !== 'componenta.queue' || $envelope->version !== 1) {
            throw new DecodingException('Unsupported queue wire format or version.');
        }

        if (
            !is_string($envelope->id)
            || !is_string($envelope->type)
            || !is_int($envelope->schemaVersion)
            || !$envelope->headers instanceof stdClass
            || !is_string($envelope->bodyBase64)
        ) {
            throw new DecodingException('Queue envelope fields have invalid types.');
        }
    }

    private function decodeBody(string $encoded): string
    {
        $length = strlen($encoded);

        if ($length % 4 !== 0) {
            throw new DecodingException('Message body is not canonical padded base64.');
        }

        $padding = str_ends_with($encoded, '==') ? 2 : (str_ends_with($encoded, '=') ? 1 : 0);
        $decodedLength = intdiv($length, 4) * 3 - $padding;

        if ($decodedLength > $this->limits->maxBodyBytes) {
            throw new DecodingException('Message body exceeds the configured byte limit.');
        }

        $body = base64_decode($encoded, true);

        if ($body === false || base64_encode($body) !== $encoded) {
            throw new DecodingException('Message body is not canonical padded base64.');
        }

        return $body;
    }

    private function rejectDuplicateMemberNames(string $json): void
    {
        // json_validate() has already checked syntax and depth. Scan tokens
        // without constructing another JSON object graph or recursing in PHP.
        /** @var list<array{object: bool, keyExpected: bool, keys: array<string,true>}> $stack */
        $stack = [];
        $depth = -1;
        $length = strlen($json);

        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $json[$offset];

            if ($character === '"') {
                $start = $offset;

                for (++$offset; $offset < $length;) {
                    // Validated strings always have a closing quote. Skip plain
                    // runs in native code, visiting only quotes and escapes in PHP.
                    $offset += strcspn($json, "\"\\", $offset);

                    if ($json[$offset] === '"') {
                        break;
                    }

                    $offset += 2;
                }

                if ($depth >= 0 && $stack[$depth]['object'] && $stack[$depth]['keyExpected']) {
                    $token = substr($json, $start, $offset - $start + 1);
                    $key = json_decode($token, false, 2, JSON_THROW_ON_ERROR);

                    if (!is_string($key)) {
                        throw new \LogicException('A validated JSON member name did not decode to a string.');
                    }

                    $index = "\0".$key;

                    if (isset($stack[$depth]['keys'][$index])) {
                        throw new DecodingException('Duplicate JSON object member names are not allowed.');
                    }

                    $stack[$depth]['keys'][$index] = true;
                    $stack[$depth]['keyExpected'] = false;
                }

                continue;
            }

            if ($character === '{' || $character === '[') {
                $stack[] = ['object' => $character === '{', 'keyExpected' => true, 'keys' => []];
                ++$depth;
            } elseif ($character === '}' || $character === ']') {
                array_pop($stack);
                --$depth;
            } elseif ($character === ',' && $depth >= 0 && $stack[$depth]['object']) {
                $stack[$depth]['keyExpected'] = true;
            }
        }
    }
}
