<?php

use App\Libraries\Rules\SenderTags;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SenderTagsTest extends CIUnitTestCase
{
    private const DOMAINS = [
        'globex.example'  => ['customer' => 'globex', 'subdomains' => true],
        'initech.example' => ['customer' => 'initech', 'subdomains' => false],
    ];

    public function testCustomerOf(): void
    {
        $this->assertSame('globex', SenderTags::customerOf('globex.example', self::DOMAINS));
        $this->assertSame('globex', SenderTags::customerOf('Mail.Srv.Globex.Example.', self::DOMAINS));
        $this->assertSame('initech', SenderTags::customerOf('initech.example', self::DOMAINS));
        $this->assertNull(SenderTags::customerOf('mail.initech.example', self::DOMAINS)); // no subdomains
        $this->assertNull(SenderTags::customerOf('example', self::DOMAINS));
        $this->assertNull(SenderTags::customerOf('notglobex.example', self::DOMAINS));
    }

    private static function auth(string $spf, ?string $spfDomain, array $dkim): array
    {
        return ['spf' => ['result' => $spf, 'domain' => $spfDomain], 'dkim' => array_map(static fn ($d) => ['result' => $d[0], 'domain' => $d[1]], $dkim)];
    }

    public function testVerify(): void
    {
        $this->assertSame([], SenderTags::verify(self::auth('pass', 'bounce.globex.example', [['pass', 'globex.example']]), 'globex', self::DOMAINS));
        // unaligned is not enough: SPF and DKIM of another domain
        $this->assertSame(['spf:foreign', 'dkim:foreign'], SenderTags::verify(self::auth('pass', 'evil.example', [['pass', 'evil.example']]), 'globex', self::DOMAINS));
        // a domain of another customer does not count either
        $this->assertSame(['spf:foreign'], SenderTags::verify(self::auth('pass', 'initech.example', [['pass', 'globex.example']]), 'globex', self::DOMAINS));
        $this->assertSame(['spf:softfail', 'dkim:none'], SenderTags::verify(self::auth('softfail', 'globex.example', []), 'globex', self::DOMAINS));
        $this->assertSame(['dkim:fail'], SenderTags::verify(self::auth('pass', 'globex.example', [['fail', 'globex.example']]), 'globex', self::DOMAINS));
        // one own valid signature among others is enough
        $this->assertSame([], SenderTags::verify(self::auth('pass', 'globex.example', [['pass', 'esp.example'], ['pass', 'globex.example']]), 'globex', self::DOMAINS));
        $this->assertSame(['spf:none', 'dkim:none'], SenderTags::verify(null, 'globex', self::DOMAINS));
    }

    public function testSchemeAddress(): void
    {
        $this->assertSame('server77_plesk_globex', SenderTags::schemeAddress('server77_plesk@globex.example', 'globex'));
    }
}
