<?php

declare(strict_types=1);

namespace Vortos\PayHere\Tests\Checkout;

use PHPUnit\Framework\TestCase;
use Vortos\PayHere\Checkout\PayHereSigner;

/**
 * The signer against fixed vectors.
 *
 * The two expected digests below were computed **outside this codebase**, from
 * PayHere's published formula, and are hard-coded. That matters: a test that
 * re-derives the expectation with the same code it is testing proves only that
 * the code is consistent with itself, and would pass just as happily if the
 * field order were wrong in both places.
 */
final class PayHereSignerTest extends TestCase
{
    private const MERCHANT_ID = '1221149';
    private const SECRET      = 'MzQ1Njc4OTAxMjM0NTY3ODkw';

    public function testCheckoutHashMatchesTheDocumentedFormula(): void
    {
        $hash = (new PayHereSigner(self::SECRET))
            ->checkoutHash(self::MERCHANT_ID, 'reg-1', '6000.00', 'LKR');

        self::assertSame('D3E8BEC20DF7B49B455EB057802B1F9E', $hash);
    }

    public function testNotificationSignatureMatchesTheDocumentedFormula(): void
    {
        $sig = (new PayHereSigner(self::SECRET))
            ->notificationSignature(self::MERCHANT_ID, 'reg-1', '6000.00', 'LKR', '2');

        self::assertSame('298D64D360E0A6076322718C341EA7CA', $sig);
    }

    /**
     * Field order is the whole signature. Swapping two values produces a
     * perfectly valid-looking digest that PayHere will never agree with, and
     * the symptom is "every payment fails" with nothing in any log to say why.
     */
    public function testFieldOrderIsLoadBearing(): void
    {
        $signer = new PayHereSigner(self::SECRET);

        self::assertNotSame(
            $signer->checkoutHash(self::MERCHANT_ID, 'reg-1', '6000.00', 'LKR'),
            $signer->checkoutHash(self::MERCHANT_ID, 'reg-1', 'LKR', '6000.00'),
        );
    }

    public function testComparisonIgnoresCaseAndSurroundingWhitespace(): void
    {
        $signer   = new PayHereSigner(self::SECRET);
        $expected = $signer->notificationSignature(self::MERCHANT_ID, 'reg-1', '6000.00', 'LKR', '2');

        // PayHere documents upper case but is not contractually consistent
        // about it, and a case mismatch would present as a forged notification
        // — sending someone hunting an attacker while payments silently fail.
        self::assertTrue($signer->matches($expected, strtolower($expected)));
        self::assertTrue($signer->matches($expected, ' ' . $expected . ' '));
        self::assertFalse($signer->matches($expected, str_repeat('0', 32)));
    }

    public function testAnEmptySecretIsRefusedAtConstruction(): void
    {
        // Every checkout would be signed with the digest of nothing, and every
        // notification would verify against that same nothing.
        $this->expectException(\InvalidArgumentException::class);

        new PayHereSigner('   ');
    }
}
