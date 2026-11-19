<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Nostr;

/**
 * A relay request that got no answer: the connection failed, the relay closed the query, or the
 * request's timeout passed.
 *
 * @internal
 */
final class RelayException extends \RuntimeException {}
