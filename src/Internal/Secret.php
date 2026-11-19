<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

/**
 * A secret value (a key, a derived key), held outside the object: in no object's properties nor
 * in a closure's variables, so that a dump of whatever holds it (var_dump, print_r, var_export,
 * an array cast, json_encode, a stack trace's arguments) shows nothing of it; reveal() alone reads
 * it. Never serialized, never cloned (SPEC.md §27).
 *
 * @internal
 */
final class Secret
{
    /** @var \WeakMap<self, string>|null the values, by their Secret: gone with it */
    private static ?\WeakMap $values = null;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        self::$values ??= new \WeakMap();
        self::$values[$this] = $value;
    }

    public function reveal(): string
    {
        \assert(self::$values !== null && isset(self::$values[$this]));

        return self::$values[$this];
    }

    public function __clone()
    {
        throw new \LogicException('A secret is never cloned');
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '[secret]'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A secret is never serialized');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A secret is never serialized');
    }
}
