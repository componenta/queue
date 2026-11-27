<?php

declare(strict_types=1);

namespace Componenta\Queue;

use Componenta\Queue\Exception\DecodingException;
use Componenta\Queue\Exception\DeliveryStateException;
use Componenta\Queue\Exception\PermanentMessageException;
use Componenta\Queue\Exception\ProcessingException;
use Componenta\Queue\Exception\ReceiverClosedException;
use Componenta\Queue\Internal\LeaseGuard;
use Componenta\Queue\Internal\ProcessingLease;
use Componenta\Queue\Retry\RetryAfter;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/** Processes one delivery; process supervision and waiting belong to the caller. */
final class Worker
{
    private bool $stopped = false;
    private bool $processing = false;

    public function __construct(
        private readonly ReceiverInterface $receiver,
        private readonly MessageCodecInterface $codec,
        private readonly MessageHandlerInterface $handler,
        private readonly RetryPolicyInterface $retryPolicy,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function processOne(int $waitMilliseconds = 0): ProcessingResult
    {
        if ($waitMilliseconds < 0) {
            throw new InvalidArgumentException('Receive wait must be non-negative.');
        }
        if ($this->stopped) {
            throw new ReceiverClosedException('This worker stopped after an infrastructure failure; create a new receiver and worker.');
        }
        if ($this->processing) {
            throw new DeliveryStateException('A worker cannot process reentrantly.');
        }

        $this->processing = true;
        $processingError = null;
        $guard = new LeaseGuard();

        try {
            $delivery = $this->receiver->receive($waitMilliseconds);
            if ($delivery === null) {
                return ProcessingResult::Idle;
            }

            $raw = $delivery->raw();
            try {
                $message = $this->codec->decode($raw);
            } catch (DecodingException $error) {
                $processingError = $error;
                $delivery->fail($this->details(FailureReason::Decode, $error));
                $this->observe(ProcessingResult::Failed);
                return ProcessingResult::Failed;
            }

            $metadata = $delivery->metadata();
            $lease = $delivery->lease();
            $context = new ProcessingContext($metadata, $lease === null ? null : new ProcessingLease($lease, $guard));

            try {
                $this->handler->handle($message, $context);
            } catch (Throwable $error) {
                $processingError = $error;
            }

            // A caught renewal error still forbids every disposition, including retry.
            if ($guard->failure !== null) {
                throw $guard->failure;
            }

            if ($processingError === null) {
                // Acknowledgment is intentionally outside the handler catch block.
                $delivery->acknowledge();
                $this->observe(ProcessingResult::Acknowledged);
                return ProcessingResult::Acknowledged;
            }

            if ($processingError instanceof PermanentMessageException) {
                $delivery->fail($this->details(FailureReason::Rejected, $processingError));
                $this->observe(ProcessingResult::Failed);
                return ProcessingResult::Failed;
            }

            $decision = $this->retryPolicy->decide(new FailureContext($message, $metadata, $processingError));
            if ($decision instanceof RetryAfter) {
                $delivery->retry($decision->delayMilliseconds);
                $this->observe(ProcessingResult::RetryScheduled);
                return ProcessingResult::RetryScheduled;
            }

            $delivery->fail($this->details(FailureReason::Handler, $processingError));
            $this->observe(ProcessingResult::Failed);
            return ProcessingResult::Failed;
        } catch (Throwable $infrastructureError) {
            $this->stopped = true;
            $guard->active = false;
            $closeError = null;
            try {
                $this->receiver->close();
            } catch (Throwable $error) {
                $closeError = $error;
            }
            if ($processingError !== null || $closeError !== null) {
                throw new ProcessingException($processingError, $infrastructureError, $closeError);
            }
            throw $infrastructureError;
        } finally {
            $guard->active = false;
            $this->processing = false;
        }
    }

    private function details(FailureReason $reason, Throwable $error): FailureDetails
    {
        // get_debug_type avoids anonymous-class filenames embedded in get_class().
        $type = get_debug_type($error);
        if (strlen($type) > 255 || preg_match('//u', $type) !== 1 || preg_match('/\p{Cc}/u', $type) !== 0) {
            $type = Throwable::class;
        }
        return new FailureDetails($reason, $type);
    }

    private function observe(ProcessingResult $result): void
    {
        try {
            // Payload, headers, exception messages and traces are deliberately absent.
            $this->logger->info('Queue processing step completed.', ['result' => $result->value]);
        } catch (Throwable) {
            // An observer cannot turn a completed settlement into a different one.
        }
    }
}
