<?php

declare(strict_types=1);

/*
 * A Secret still referenced at shutdown, when PHP calls every destructor: made before its reader,
 * its turn comes first. Prints what the reader's destructor reads.
 * Usage: php secret-at-shutdown.php <the Secret class's file> <its name>
 */

require $argv[1];

final class Reader
{
    public static ?self $kept = null;

    public function __construct(private readonly \Closure $reveal) {}

    public function __destruct()
    {
        $value = ($this->reveal)();
        echo \is_string($value) ? $value : '(not a string)';
    }
}

$secret = new $argv[2]('VALUE');
$reveal = [$secret, 'reveal'];
if (!\is_callable($reveal)) {
    exit(1);
}
Reader::$kept = new Reader(\Closure::fromCallable($reveal));
