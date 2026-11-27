<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Componenta\Queue\Codec\JsonMessageCodec;
use Componenta\Queue\Handler\CallbackHandler;
use Componenta\Queue\Handler\HandlerRegistration;
use Componenta\Queue\Handler\HandlerRegistry;
use Componenta\Queue\InMemory\InMemoryQueue;
use Componenta\Queue\Message;
use Componenta\Queue\MessageLimits;
use Componenta\Queue\MessageType;
use Componenta\Queue\Retry\NoRetryPolicy;
use Componenta\Queue\Worker;
use Psr\Clock\ClockInterface;

$clock = new class () implements ClockInterface {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
};
$codec = new JsonMessageCodec(new MessageLimits(
    maxBodyBytes: 65_536,
    maxWireBytes: 100_000,
    maxHeaderBytes: 4096,
    maxJsonDepth: 8,
));
$queue = new InMemoryQueue('notifications', $codec, $clock, leaseMilliseconds: 30_000, maxDeliveries: 5);
$type = new MessageType('notification.send', schemaVersion: 1);
$handler = new HandlerRegistry([
    new HandlerRegistration($type, new CallbackHandler(static function (Message $message): void {
        // Replace this demonstration with an application effect that tolerates redelivery.
        printf("Handled %s: %s\n", $message->id, $message->body);
    })),
]);

$queue->publisher()->publish(new Message('notification-123', $type, 'Hello from Componenta Queue'));
$receiver = $queue->receiver();
$worker = new Worker($receiver, $codec, $handler, new NoRetryPolicy());
try {
    printf("Queue result: %s\n", $worker->processOne()->value);
} finally {
    $receiver->close();
}
