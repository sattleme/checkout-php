<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Loop;

/**
 * A wait whose deadline passed.
 *
 * @internal
 */
final class TimeoutException extends \RuntimeException {}
