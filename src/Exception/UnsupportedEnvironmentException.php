<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Exception;

/**
 * The PHP that runs the SDK lacks what it needs (64-bit integers, an extension, OpenSSL with the
 * secp256k1 curve and ChaCha20): thrown when a Client is made, before any request. Not an error of
 * the protocol (no SattlemeException): the server's setup, to fix once.
 */
final class UnsupportedEnvironmentException extends \RuntimeException {}
