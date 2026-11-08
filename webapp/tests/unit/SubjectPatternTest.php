<?php

use App\Libraries\Rules\PatternSuggester;
use App\Libraries\Rules\SubjectPattern;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Subject patterns and pattern proposals (E-029).
 *
 * @internal
 */
final class SubjectPatternTest extends CIUnitTestCase
{
    private const KNOWN = [
        'customers' => ['acme' => ['acme'], 'acmegmbh' => ['acme'], 'initech' => ['initech'], 'initechde' => ['initech'], 'twin' => ['acme', 'initech']],
        'locations' => ['acme' => ['cloud' => ['cloud'], 'hq1' => ['hq1'], 'headquarters' => ['hq1']]],
        'devices'   => ['acme' => ['srv01' => ['acme.srv01'], 'server01' => ['acme.srv01']], 'initech' => ['nas05' => ['initech.nas05']]],
    ];

    public function testNormalizeValidatesPlaceholders(): void
    {
        $this->assertSame('Error report - {kunde}, {standort}, {geraet}', SubjectPattern::normalize("  Error report -  {kunde}, {standort},\t{geraet} "));
        $this->assertSame('{*} at {kunde}: {geraet}', SubjectPattern::normalize('{*} at {kunde}: {geraet}'));
        foreach (['', 'no placeholder', '{geraet} only', '{kunde} {kunde}', '{kunde}{geraet}', '{kunde} {host}', '{kunde} } x', str_repeat('x', 250) . ' {kunde}'] as $bad) {
            try {
                SubjectPattern::normalize($bad);
                $this->fail('accepted: ' . $bad);
            } catch (InvalidArgumentException $e) {
                $this->assertSame('pattern', $e->getMessage());
            }
        }
    }

    public function testMatchCapturesValuesLiterally(): void
    {
        $p = 'Error report - {kunde}, {standort}, {geraet}';
        $this->assertSame(['kunde' => 'Acme GmbH', 'standort' => 'Cloud', 'geraet' => 'SRV01'], SubjectPattern::match($p, 'error report  - Acme GmbH, Cloud, SRV01'));
        $this->assertNull(SubjectPattern::match($p, 'Status report - Acme, Cloud, srv01'));
        $this->assertNull(SubjectPattern::match($p, 'Error report (x) - Acme, Cloud, srv01'));
        // regex characters in the text are literal; {*} swallows any text
        $this->assertSame(['kunde' => 'Acme', 'geraet' => 'nb-01'], SubjectPattern::match('[{*}] {kunde}, {geraet} (x+y)', '[ALERT 3] Acme, nb-01 (x+y)'));
    }

    public function testKeyAndCodeIgnoreCaseUmlautsAndPunctuation(): void
    {
        $this->assertSame('kopfsteine', SubjectPattern::key('Kopf & Steine'));
        $this->assertSame('muellerbaeckerei', SubjectPattern::key('Müller-Bäckerei'));
        $this->assertSame('cafe', SubjectPattern::key('Café'));
        $this->assertSame('kopf-steine', SubjectPattern::code('Kopf & Steine'));
        $this->assertSame('fw-edge-2016r2', SubjectPattern::code('FW-EDGE-2016R2'));
    }

    public function testResolveUsesNamesAndAliases(): void
    {
        $r = SubjectPattern::resolve(['kunde' => 'ACME GmbH', 'standort' => 'Headquarters', 'geraet' => 'Server 01'], self::KNOWN);
        $this->assertSame(['kunde:acme', 'standort:acme.hq1', 'geraet:acme.srv01'], $r['tags']);
        $this->assertSame('acme.srv01', $r['device']);
        $this->assertSame([], $r['unknown']);

        $r = SubjectPattern::resolve(['kunde' => 'InitechDE', 'geraet' => 'NAS05'], self::KNOWN);
        $this->assertSame(['kunde:initech', 'geraet:initech.nas05'], $r['tags']);
    }

    public function testResolveReportsUnknownAndAmbiguousValues(): void
    {
        $r = SubjectPattern::resolve(['kunde' => 'Globex', 'standort' => 'X', 'geraet' => 'Y'], self::KNOWN);
        $this->assertSame([['part' => 'customer', 'value' => 'Globex', 'customer' => null]], $r['unknown']);
        $this->assertSame([], $r['tags']);

        // device of another customer is unknown for this one; all unknown parts are reported
        $r = SubjectPattern::resolve(['kunde' => 'acme', 'standort' => 'Branch', 'geraet' => 'nas05'], self::KNOWN);
        $this->assertSame([['part' => 'location', 'value' => 'Branch', 'customer' => 'acme'], ['part' => 'device', 'value' => 'nas05', 'customer' => 'acme']], $r['unknown']);
        $this->assertSame([], $r['tags']);

        $r = SubjectPattern::resolve(['kunde' => 'Twin'], self::KNOWN);
        $this->assertSame('ambiguous', $r['unknown'][0]['part']);
    }

    public function testOptionalPlaceholdersAreIgnoredWhenUnknown(): void
    {
        $p = SubjectPattern::normalize('Error report - {kunde}, {standort?}, {geraet?}');
        $this->assertSame(['standort', 'geraet'], SubjectPattern::optional($p));
        $values = SubjectPattern::match($p, 'Error report - Acme, Branch, banking01');
        $this->assertSame(['kunde' => 'Acme', 'standort' => 'Branch', 'geraet' => 'banking01'], $values);
        // unknown optional values: no error, only the known parts are tagged
        $r = SubjectPattern::resolve($values, self::KNOWN, SubjectPattern::optional($p));
        $this->assertSame(['kunde:acme'], $r['tags']);
        $this->assertNull($r['device']);
        $this->assertSame([], $r['unknown']);
        // known optional values are used
        $r = SubjectPattern::resolve(['kunde' => 'acme', 'standort' => 'Cloud', 'geraet' => 'srv01'], self::KNOWN, ['standort', 'geraet']);
        $this->assertSame(['kunde:acme', 'standort:acme.cloud', 'geraet:acme.srv01'], $r['tags']);
        // only the optional part is lenient
        $r = SubjectPattern::resolve(['kunde' => 'acme', 'standort' => 'Branch', 'geraet' => 'x'], self::KNOWN, ['geraet']);
        $this->assertSame('location', $r['unknown'][0]['part']);
        foreach (['{kunde?} {geraet}', '{kunde} {geraet} {geraet?}', '{kunde} {*?}'] as $bad) {
            try {
                SubjectPattern::normalize($bad);
                $this->fail('accepted: ' . $bad);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSuggesterFindsTemplatesAndAssignsPlaceholders(): void
    {
        $rows = [];
        foreach ([['Globex', 'Cloud', 'srv1'], ['Globex', 'Cloud', 'srv2'], ['Hooli', 'Office', 'nb-7'], ['Acme GmbH', 'HQ', 'fw1']] as [$c, $l, $d]) {
            $rows[] = ['source' => 'n-sight', 'subject' => "Error report - {$c}, {$l}, {$d}"];
        }
        $rows[] = ['source' => 'n-sight', 'subject' => 'Problem at Globex, tests on vm-1'];
        $rows[] = ['source' => 'n-sight', 'subject' => 'Problem at Hooli, tests on vm-2'];
        $rows[] = ['source' => 'n-sight', 'subject' => 'Weekly summary']; // nothing variable: no proposal
        $rows[] = ['source' => 'plesk', 'subject' => 'Backup of acme srv01 failed']; // single subject with known values

        $s = PatternSuggester::suggest($rows, ['acme' => 'kunde', 'acmegmbh' => 'kunde', 'srv01' => 'geraet']);
        $this->assertSame([
            ['source' => 'n-sight', 'pattern' => 'Error report - {kunde}, {standort}, {geraet}', 'messages' => 4],
            ['source' => 'n-sight', 'pattern' => 'Problem at {kunde}, tests on {geraet}', 'messages' => 2],
            ['source' => 'plesk', 'pattern' => 'Backup of {kunde} {geraet} failed', 'messages' => 1],
        ], array_map(static fn ($x) => array_diff_key($x, ['examples' => 0]), $s));
        $this->assertCount(3, $s[0]['examples']);
        // saved patterns are not proposed again
        $this->assertCount(2, PatternSuggester::suggest($rows, ['acme' => 'kunde', 'acmegmbh' => 'kunde', 'srv01' => 'geraet'], ["n-sight\nError report - {kunde}, {standort}, {geraet}"]));
    }
}
