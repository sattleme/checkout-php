<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Environment;

/** What the SDK needs of PHP: here, all of it, OpenSSL's known answers included. */
final class EnvironmentTest extends TestCase
{
    public function testThisPhpHasWhatTheSdkNeeds(): void
    {
        Environment::check();
        Environment::check(); // Once checked, never again.
        $this->addToAssertionCount(1);
    }

    public function testOpenSslGivesTheKnownAnswers(): void
    {
        self::assertTrue((new \ReflectionMethod(Environment::class, 'computes'))->invoke(null));
    }
}
