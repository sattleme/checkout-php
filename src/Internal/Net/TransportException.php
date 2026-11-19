<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Net;

/**
 * A connection that failed, timed out or ended too early: no usable answer.
 *
 * @internal
 */
final class TransportException extends \RuntimeException {}
