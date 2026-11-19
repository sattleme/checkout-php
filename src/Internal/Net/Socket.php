<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Net;

use Sattleme\Checkout\Internal\Loop\Loop;

/**
 * A non-blocking connection, read and written by tasks of the loop. A failure, a deadline passed
 * or the peer closing is a TransportException.
 *
 * @internal
 */
final class Socket
{
    private const CHUNK = 65536;

    private bool $closed = false;

    /** @param resource $stream */
    public function __construct(private readonly mixed $stream)
    {
        stream_set_blocking($stream, false);
        stream_set_read_buffer($stream, 0);
        stream_set_write_buffer($stream, 0);
    }

    /**
     * The next bytes received (never empty), or null once the peer closed the connection.
     *
     * @throws TransportException
     */
    public function read(?float $deadline): ?string
    {
        $loop = Loop::get();
        while (true) {
            $this->assertOpen();
            $data = self::quietly(fn(): string|false => fread($this->stream, self::CHUNK));
            if ($data === false) {
                throw new TransportException('Connection lost');
            }
            if ($data !== '') {
                // A peer that never stops sending holds neither the loop nor the deadline.
                $loop->checkpoint();
                if ($deadline !== null && Loop::now() >= $deadline) {
                    throw new TransportException('Timed out');
                }

                return $data;
            }
            if (feof($this->stream)) {
                return null;
            }
            if (!$loop->awaitReadable($this->stream, $deadline) && $deadline !== null && Loop::now() >= $deadline) {
                throw new TransportException('Timed out');
            }
        }
    }

    /**
     * Writes all of $data.
     *
     * @throws TransportException
     */
    public function write(#[\SensitiveParameter] string $data, ?float $deadline): void
    {
        $loop = Loop::get();
        while ($data !== '') {
            $this->assertOpen();
            $written = self::quietly(fn(): int|false => fwrite($this->stream, $data));
            if ($written === false) {
                throw new TransportException('Connection lost');
            }
            if ($written > 0) {
                $data = substr($data, $written);
                continue;
            }
            // Nothing written to a connection the peer closed (a TLS one reads writable, and
            // fwrite() gives 0 at each try): lost, never tried again until the deadline.
            if (feof($this->stream)) {
                throw new TransportException('Connection lost');
            }
            if (!$loop->awaitWritable($this->stream, $deadline) && $deadline !== null && Loop::now() >= $deadline) {
                throw new TransportException('Timed out');
            }
            if ($deadline !== null && Loop::now() >= $deadline) {
                throw new TransportException('Timed out');
            }
        }
    }

    /** Writes what of $data can go at once, without waiting (a close frame before closing). */
    public function tryWrite(string $data): void
    {
        if (!$this->closed && \is_resource($this->stream)) {
            self::quietly(fn(): int|false => fwrite($this->stream, $data));
        }
    }

    /** Closes the connection now, without waiting: what is waiting on it wakes up. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        Loop::get()->forget($this->stream);
        self::quietly(fn(): bool => fclose($this->stream));
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function assertOpen(): void
    {
        if ($this->closed || !\is_resource($this->stream)) {
            throw new TransportException('Connection closed');
        }
    }

    /**
     * Runs a stream call without its warnings (an SSL error, a reset connection): its result
     * says what happened, and a warning would reach the host's error log for nothing.
     *
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    private static function quietly(\Closure $call): mixed
    {
        set_error_handler(static fn(): bool => true);
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
