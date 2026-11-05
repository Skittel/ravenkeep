<?php

use App\Libraries\Rules\SenderTags;
use App\Libraries\Senders\SenderRules;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SenderTagsTest extends CIUnitTestCase
{
    private const SENDERS = [
        ['pattern' => 'globex.example', 'auth_check' => 'spf_or_dkim', 'customer' => 'globex', 'source' => null],
        ['pattern' => 'plesk.example', 'auth_check' => 'spf', 'customer' => null, 'source' => 'plesk'],
        ['pattern' => 'alerts@globex.example', 'auth_check' => 'none', 'customer' => null, 'source' => 'alerts'],
        ['pattern' => 'statuscake.com', 'auth_check' => 'spf_or_dkim', 'customer' => null, 'source' => null],
    ];

    private const KNOWN = [
        'customers' => ['globex', 'initech'],
        'locations' => ['globex.hq1'],
        'devices'   => ['globex.srv71', 'globex.nas1', 'initech.nas1'],
        'sources'   => ['plesk'],
    ];

    public function testRuleIsTheMostSpecificWithAMeaning(): void
    {
        $this->assertSame('globex', SenderTags::rule('srv71@mail.globex.example', self::SENDERS)['customer']);
        $this->assertSame('alerts', SenderTags::rule('alerts@globex.example', self::SENDERS)['source']);
        $this->assertSame('plesk', SenderTags::rule('srv71@plesk.example', self::SENDERS)['source']);
        $this->assertNull(SenderTags::rule('noreply@statuscake.com', self::SENDERS)); // no meaning
    }

    public function testCustomerDomain(): void
    {
        $r = SenderTags::resolve('srv71_plesk@globex.example', self::SENDERS[0], self::KNOWN);
        $this->assertSame(['kunde:globex', 'source:plesk', 'geraet:globex.srv71'], $r['tags']);
    }

    public function testSourceDomainFindsTheDeviceAtAnyCustomer(): void
    {
        $r = SenderTags::resolve('srv71@plesk.example', self::SENDERS[1], self::KNOWN);
        $this->assertSame(['kunde:globex', 'geraet:globex.srv71', 'source:plesk'], $r['tags']);
        $this->assertSame('globex.srv71', $r['device']);

        $this->assertSame([['part' => 'device', 'value' => 'srv99']], SenderTags::resolve('srv99@plesk.example', self::SENDERS[1], self::KNOWN)['unknown']);
        $this->assertSame([['part' => 'ambiguous', 'value' => 'nas1']], SenderTags::resolve('nas1@plesk.example', self::SENDERS[1], self::KNOWN)['unknown']);
        // a mapping decides
        $this->assertSame('initech.nas1', SenderTags::resolve('nas1@plesk.example', self::SENDERS[1], self::KNOWN, 'initech.nas1')['device']);
        // levels stay free: gc.srv71 = device srv71, message type gc
        $this->assertContains('meldung:gc', SenderTags::resolve('gc.srv71@plesk.example', self::SENDERS[1], self::KNOWN)['tags']);
    }

    public function testChecks(): void
    {
        $auth = static fn (bool $spf, bool $dkim) => ['alignment' => ['spf_aligned' => $spf, 'dkim_aligned' => $dkim]];
        $this->assertTrue(SenderRules::passes('spf', $auth(true, false)));
        $this->assertFalse(SenderRules::passes('spf_and_dkim', $auth(true, false)));
        $this->assertTrue(SenderRules::passes('spf_and_dkim', $auth(true, true)));
        $this->assertFalse(SenderRules::passes('dkim', $auth(true, false)));
        $this->assertTrue(SenderRules::passes('spf_or_dkim', $auth(false, true)));
        $this->assertFalse(SenderRules::passes('spf_or_dkim', $auth(false, false)));
        $this->assertTrue(SenderRules::passes('none', $auth(false, false)));
        // without analysis only the overall verdict counts
        $this->assertTrue(SenderRules::passes('spf_or_dkim', null, 'accepted'));
        $this->assertFalse(SenderRules::passes('spf', null, 'accepted'));
    }

    public function testClassifyUsesTheCheckOfTheRule(): void
    {
        $rules = new SenderRules([
            ['pattern' => 'plesk.example', 'target' => 'header', 'action' => 'allow', 'auth_check' => 'spf_and_dkim'],
            ['pattern' => 'bounce.example', 'target' => 'envelope', 'action' => 'allow', 'auth_check' => 'spf'],
        ]);
        $msg = static fn (string $env, string $from, bool $spf, bool $dkim) => ['envelope_sender' => $env, 'header_from_address' => $from,
            'auth_verdict' => 'accepted', 'auth' => ['alignment' => ['spf_aligned' => $spf, 'dkim_aligned' => $dkim]]];
        $this->assertSame('rejected', $rules->classify($msg('x@other.example', 'srv71@plesk.example', true, false))['status']);
        $this->assertSame('accepted', $rules->classify($msg('x@other.example', 'srv71@plesk.example', true, true))['status']);
        // a second matching allow rule whose check passes accepts
        $r = $rules->classify($msg('x@bounce.example', 'srv71@plesk.example', true, false));
        $this->assertSame(['accepted', 'bounce.example'], [$r['status'], $r['list_match_pattern']]);
    }
}
