<?php

use App\Libraries\Senders\SenderRules;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Sender matching must be identical to the MX node: both use tests-shared/sender_match_cases.json.
 *
 * @internal
 */
final class SenderRulesTest extends CIUnitTestCase
{
    private static function cases(): array
    {
        return json_decode((string) file_get_contents(ROOTPATH . '../tests-shared/sender_match_cases.json'), true);
    }

    public function testSharedAddressMatchCases(): void
    {
        foreach (self::cases()['address_matches'] as $case) {
            $this->assertSame($case['expected'], SenderRules::addressMatches($case['address'], $case['pattern']), json_encode($case));
        }
    }

    public function testSharedFindMatchCases(): void
    {
        foreach (self::cases()['find_match'] as $case) {
            $rules = new SenderRules(array_map(static fn ($r) => ['pattern' => $r[0], 'target' => $r[1], 'action' => 'allow'], $case['rules']));
            $this->assertSame($case['expected'], $rules->findMatch('allow', $case['envelope'], $case['header']), json_encode($case));
        }
    }

    public function testClassify(): void
    {
        $rules = new SenderRules([
            ['pattern' => 'spam.example', 'target' => 'envelope', 'action' => 'block'],
            ['pattern' => 'monitor.example', 'target' => 'envelope', 'action' => 'allow'],
        ]);
        $this->assertSame('blocked', $rules->classify(['envelope_sender' => 'a@spam.example', 'auth_verdict' => 'accepted'])['status']);
        $this->assertSame('accepted', $rules->classify(['envelope_sender' => 'a@monitor.example', 'auth_verdict' => 'accepted'])['status']);
        $this->assertSame('rejected', $rules->classify(['envelope_sender' => 'a@monitor.example', 'auth_verdict' => 'rejected'])['status']);
        $result = $rules->classify(['envelope_sender' => 'a@other.example', 'auth_verdict' => 'accepted']);
        $this->assertSame(['status' => 'pending', 'list_match_pattern' => null, 'list_match_target' => null], $result);
    }

    public function testNormalizePattern(): void
    {
        $this->assertSame('statuscake.com', SenderRules::normalizePattern('  StatusCake.COM. '));
        $this->assertSame('alerts@plesk.example', SenderRules::normalizePattern('<Alerts@Plesk.example>'));
        foreach (['com', 'a@@b.example', 'bad pattern.example', '', '@example.com', 'x@-bad.example'] as $bad) {
            try {
                SenderRules::normalizePattern($bad);
                $this->fail("accepted invalid pattern {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRenderedFileIsAcceptedByNodeFormat(): void
    {
        $rules = new SenderRules([
            ['pattern' => 'b.example', 'target' => 'header', 'action' => 'allow'],
            ['pattern' => 'a.example', 'target' => 'envelope', 'action' => 'allow'],
            ['pattern' => 'spam.example', 'target' => 'envelope', 'action' => 'block'],
        ]);
        $lines = array_values(array_filter(explode("\n", $rules->render('allow')), static fn ($l) => $l !== '' && $l[0] !== '#'));
        $this->assertSame(['a.example', 'b.example'], array_map(static fn ($l) => preg_split('/\s+/', $l)[0], $lines));
        $this->assertStringEndsWith('header', $lines[1]);
        $this->assertStringNotContainsString('spam.example', $rules->render('allow'));
    }
}
