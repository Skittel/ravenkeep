<?php

use App\Libraries\Rules\RuleEngine;
use App\Libraries\Rules\TagName;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class RuleEngineTest extends CIUnitTestCase
{
    private static function rule(int $id, array $conditions, array $tags, string $mode = 'all', bool $stop = false): array
    {
        return ['id' => $id, 'name' => "r{$id}", 'match_mode' => $mode, 'stop' => $stop,
            'conditions' => array_map(static fn ($c) => ['field' => $c[0], 'operator' => $c[1], 'value' => $c[2]], $conditions),
            'actions'    => array_map(static fn ($t) => ['type' => 'add_tag', 'value' => $t], $tags)];
    }

    /** Example: rules building on each other (customer tag, device tag, then error status). */
    public function testWikiExampleRulesBuildOnEachOther(): void
    {
        $engine = new RuleEngine([
            self::rule(1, [['envelope_sender', 'ends_with', '@acme.example']], ['kunde:acme']),
            self::rule(2, [['envelope_sender', 'equals', 'nas-01@acme.example']], ['typ:nas']),
            self::rule(3, [['tag', 'has', 'kunde:acme'], ['tag', 'has', 'typ:nas'], ['subject', 'contains', '[Error]']], ['status:error']),
        ]);

        $error = $engine->evaluate(['envelope_sender' => 'NAS-01@acme.example', 'subject' => '[Error] Volume degraded']);
        $this->assertSame(['kunde:acme' => 1, 'typ:nas' => 2, 'status:error' => 3], $error['tags']);

        $info = $engine->evaluate(['envelope_sender' => 'nas-01@acme.example', 'subject' => '[Info] ok']);
        $this->assertSame(['kunde:acme' => 1, 'typ:nas' => 2], $info['tags']);

        $other = $engine->evaluate(['envelope_sender' => 'pbs@acme.example', 'subject' => '[Error] x']);
        $this->assertSame(['kunde:acme' => 1], $other['tags']);
    }

    public function testOrderMattersRulesOnlySeeEarlierTags(): void
    {
        $engine = new RuleEngine([
            self::rule(1, [['tag', 'has', 'typ:nas']], ['seen']),
            self::rule(2, [['subject', 'contains', 'nas']], ['typ:nas']),
        ]);
        $this->assertSame(['typ:nas' => 2], $engine->evaluate(['subject' => 'NAS report'])['tags']);
    }

    public function testAnyModeStopAndManualTags(): void
    {
        $engine = new RuleEngine([
            self::rule(1, [['subject', 'contains', 'backup'], ['subject', 'contains', 'verify']], ['aufgabe:pbs'], 'any', true),
            self::rule(2, [['subject', 'contains', 'verify']], ['never']),
            self::rule(3, [['tag', 'has', 'kunde:*']], ['has-customer']),
        ]);
        $this->assertSame(['aufgabe:pbs' => 1], $engine->evaluate(['subject' => 'Verify OK'])['tags']);
        $this->assertSame(['has-customer' => 3], $engine->evaluate(['subject' => 'x'], ['kunde:xy'])['tags']);
        $this->assertSame([], $engine->evaluate(['subject' => 'x'], ['kundexy'])['tags']);
    }

    public function testOperatorsAndFields(): void
    {
        $m = ['subject' => 'Disk space on SRV-01 below 20%', 'body_text' => 'Free: 18 %', 'sasl_username' => 'acme.nas01@customer.ravenkeep.cloud',
            'header_from_address' => 'alerts@plesk.example', 'client_ip' => '192.0.2.10'];
        $c = static fn ($f, $o, $v) => RuleEngine::condition(['field' => $f, 'operator' => $o, 'value' => $v], $m, ['typ:nas']);

        $this->assertTrue($c('subject', 'wildcard', 'disk space on srv-?? below *'));
        $this->assertFalse($c('subject', 'wildcard', 'disk space'));          // wildcard is a whole-value match
        $this->assertTrue($c('body', 'contains', '18 %'));
        $this->assertTrue($c('sasl_username', 'starts_with', 'acme.'));
        $this->assertTrue($c('header_from', 'equals', 'Alerts@Plesk.example'));
        $this->assertTrue($c('header_from', 'not_equals', 'x@plesk.example'));
        $this->assertTrue($c('client_ip', 'ends_with', '.10'));
        $this->assertTrue($c('subject', 'not_contains', 'error'));
        $this->assertFalse($c('subject', 'contains', ''));                   // empty value never matches "contains"
        $this->assertTrue($c('tag', 'has_not', 'status:error'));
        $this->assertTrue($c('tag', 'has', 'typ:*'));
        $this->assertTrue($c('subject', 'wildcard', '*20%'));                 // regex characters are literal
    }

    public function testRuleWithoutConditionsNeverMatches(): void
    {
        $this->assertSame([], (new RuleEngine([self::rule(1, [], ['all'])]))->evaluate(['subject' => 'x'])['tags']);
    }

    public function testTagNames(): void
    {
        $this->assertSame('kunde:acme', TagName::normalize('  Kunde:ACME '));
        $this->assertSame('standort:bremen-nord', TagName::normalize('Standort:Bremen Nord'));
        $this->assertSame('größe:groß', TagName::normalize('Größe:Groß'));
        $this->assertSame('kunde', TagName::type('kunde:acme'));
        $this->assertSame('', TagName::type('wichtig'));
        foreach (['', 'kunde:', ':x', 'a::b', 'a<b', str_repeat('x', 65)] as $bad) {
            try {
                TagName::normalize($bad);
                $this->fail("accepted invalid tag {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
