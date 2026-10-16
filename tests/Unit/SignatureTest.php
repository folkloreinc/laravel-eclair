<?php

namespace Folklore\Eclair\Tests\Unit;

use Folklore\Eclair\Support\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    private const NOW = 1760536992;

    public function test_it_signs_like_eclair(): void
    {
        // Computed with node:crypto, as Eclair signs.
        $this->assertSame(
            't=1760536992,v1=38a029695219d6d0c41750921635f5eff3171beedc856f931386f45ff9ca86e2',
            Signature::sign('whsec_test', '{"id":"job_1"}', self::NOW),
        );
        $this->assertSame(
            't=1760536992,v1=69c3ef62396c72914a0a2dd492ea138f3b75777434c4d19df28a84dc5b44c037',
            Signature::sign('whsec_test', Signature::storagePayload('videos/42/a b.mp4', hash('sha256', 'file')), self::NOW),
        );
        $this->assertSame(hash('sha256', ''), Signature::EMPTY_SHA256);
    }

    public function test_it_verifies_a_signature(): void
    {
        $header = Signature::sign('whsec_test', 'payload', self::NOW);

        $this->assertTrue(Signature::verify($header, 'whsec_test', 'payload', now: self::NOW + 10));
        $this->assertFalse(Signature::verify($header, 'whsec_test', 'payload ', now: self::NOW));
        $this->assertFalse(Signature::verify($header, 'whsec_other', 'payload', now: self::NOW));
        $this->assertFalse(Signature::verify($header, '', 'payload', now: self::NOW));
        $this->assertFalse(Signature::verify(null, 'whsec_test', 'payload', now: self::NOW));
        $this->assertFalse(Signature::verify('garbage', 'whsec_test', 'payload', now: self::NOW));
    }

    public function test_it_refuses_old_and_future_timestamps(): void
    {
        $header = Signature::sign('whsec_test', 'payload', self::NOW);

        $this->assertTrue(Signature::verify($header, 'whsec_test', 'payload', now: self::NOW + 300));
        $this->assertFalse(Signature::verify($header, 'whsec_test', 'payload', now: self::NOW + 301));
        $this->assertFalse(Signature::verify($header, 'whsec_test', 'payload', now: self::NOW - 301));
        $this->assertTrue(Signature::verify($header, 'whsec_test', 'payload', tolerance: 600, now: self::NOW + 400));
    }

    public function test_it_accepts_one_of_several_signatures(): void
    {
        $valid = explode('v1=', Signature::sign('whsec_new', 'payload', self::NOW))[1];
        $header = sprintf('t=%d,v1=%s,v1=%s', self::NOW, str_repeat('0', 64), $valid);

        $this->assertTrue(Signature::verify($header, 'whsec_new', 'payload', now: self::NOW));
    }
}
