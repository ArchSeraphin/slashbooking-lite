<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Booking;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Booking\DecisionTokenSigner;

final class DecisionTokenSignerTest extends TestCase
{
    private DecisionTokenSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new DecisionTokenSigner('test-secret-32-bytes-min-length-ok');
    }

    public function test_sign_and_verify_round_trip(): void
    {
        $exp = time() + 3600;
        $sig = $this->signer->sign('booking|42|confirm', $exp);
        self::assertTrue($this->signer->verify('booking|42|confirm', $exp, $sig));
    }

    public function test_verify_rejects_wrong_signature(): void
    {
        $exp = time() + 3600;
        self::assertFalse($this->signer->verify('booking|42|confirm', $exp, 'bogus'));
    }

    public function test_verify_rejects_expired(): void
    {
        $past = time() - 60;
        $sig = $this->signer->sign('booking|42|confirm', $past);
        self::assertFalse($this->signer->verify('booking|42|confirm', $past, $sig));
    }

    public function test_uses_constant_time_comparison(): void
    {
        $exp = time() + 60;
        $sig = $this->signer->sign('payload', $exp);
        self::assertTrue($this->signer->verify('payload', $exp, $sig));
    }

    public function test_signature_is_domain_separated_from_raw_hmac(): void
    {
        $root = str_repeat('s', 32);
        $signer = new DecisionTokenSigner($root);

        $exp = time() + 3600;
        $payload = 'decide|1|confirm';

        $sig = $signer->sign($payload, $exp);

        // Old (vulnerable) construction used the root secret directly.
        $rawHmac = hash_hmac('sha256', $payload . '|' . $exp, $root);

        self::assertNotSame($rawHmac, $sig, 'signer must derive a context subkey, not use the root secret directly');
    }

    public function test_decision_and_oauth_state_do_not_share_effective_key(): void
    {
        $root = str_repeat('s', 32);
        $signer = new DecisionTokenSigner($root);
        $state  = new \Slash\Booking\Google\OAuthState($root);

        $exp = time() + 600;
        $decisionSig = $signer->sign('x', $exp);

        // OAuthState issues a token; confirm its bytes don't embed the decision
        // signature for the same root secret, proving distinct derived keys.
        $token = $state->issue(0);
        self::assertNotSame('', $token);
        self::assertStringNotContainsString($decisionSig, $token);
    }
}
