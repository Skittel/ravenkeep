<?php

use App\Libraries\Rules\AddressTags;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Address scheme (E-016, E-018, E-019): the last group is the customer; only known values yield tags,
 * anything unknown is reported and the address yields no tags at all.
 *
 * @internal
 */
final class AddressTagsTest extends CIUnitTestCase
{
    private const KNOWN = [
        'customers' => ['acme', 'globex', 'initech'],
        'locations' => ['acme.hq1', 'acme.br2'],
        'devices'   => ['acme.nas05', 'acme.pm10', 'acme.pm01', 'acme.hyper-v01', 'globex.srv01', 'globex.nas05', 'initech.fw01'],
        'sources'   => ['n-sight', 'plesk', 'proxmox', 'hyper-v'],
    ];

    public static function resolved(): array
    {
        return [
            'source of a customer'    => ['n-sight_initech@report.example', ['kunde:initech', 'source:n-sight']],
            'known device, 2 groups'  => ['fw01_initech@report.example', ['kunde:initech', 'geraet:initech.fw01']],
            'source with sub-address' => ['gc.n-sight_initech@x', ['kunde:initech', 'source:n-sight', 'meldung:gc']],
            'device at a location'    => ['nas05_hq1_acme@report.example', ['kunde:acme', 'standort:acme.hq1', 'geraet:acme.nas05']],
            'device with a source'    => ['srv01_plesk_globex@report.example', ['kunde:globex', 'source:plesk', 'geraet:globex.srv01']],
            'levels are free'         => ['nas05_r12.2og.hq1_acme@x', ['kunde:acme', 'standort:acme.hq1', 'standort:acme.hq1.2og', 'standort:acme.hq1.2og.r12', 'geraet:acme.nas05']],
            'full example'            => ['gc.pm10_ve.proxmox_2og.hq1_acme@report.example', [
                'kunde:acme', 'standort:acme.hq1', 'standort:acme.hq1.2og', 'source:proxmox', 'source:proxmox.ve', 'geraet:acme.pm10', 'meldung:gc']],
            'upper case'              => ['NAS05_HQ1_ACME@report.example', ['kunde:acme', 'standort:acme.hq1', 'geraet:acme.nas05']],
            'hyphens in names'        => ['hyper-v01_hyper-v_br2_acme@x', ['kunde:acme', 'standort:acme.br2', 'source:hyper-v', 'geraet:acme.hyper-v01']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('resolved')]
    public function testResolved(string $address, array $expected): void
    {
        $r = AddressTags::resolve($address, self::KNOWN);
        $this->assertSame([], $r['unknown']);
        $this->assertSame($expected, $r['tags']);
    }

    public static function unresolved(): array
    {
        return [
            'unknown customer'           => ['nas05_hq1_other@x', [['part' => 'customer', 'value' => 'other']]],
            'neither device nor source'  => ['srv17_globex@x', [['part' => 'device_or_source', 'value' => 'srv17']]],
            'typo in the device'         => ['srv17_plesk_globex@x', [['part' => 'device', 'value' => 'srv17']]],
            'location of another customer' => ['nas05_hq1_globex@x', [['part' => 'location_or_source', 'value' => 'hq1']]],
            'typo in the location'       => ['nas05_hqq_acme@x', [['part' => 'location_or_source', 'value' => 'hqq']]],
            '4 groups, all checked'      => ['pm99_prxmox_hb_acme@x', [['part' => 'device', 'value' => 'pm99'],
                ['part' => 'source', 'value' => 'prxmox'], ['part' => 'location', 'value' => 'hb']]],
            'one group'                  => ['test01@customer.example', [['part' => 'scheme', 'value' => '']]],
            'five groups'                => ['a_b_c_d_acme@x', [['part' => 'scheme', 'value' => '']]],
            'empty group'                => ['nas05__acme@x', [['part' => 'scheme', 'value' => '']]],
            'dotted customer'            => ['nas05_hq1_x.acme@x', [['part' => 'scheme', 'value' => '']]],
            'invalid characters'         => ['nas+05_acme@x', [['part' => 'scheme', 'value' => '']]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unresolved')]
    public function testUnresolvedYieldsNoTags(string $address, array $unknown): void
    {
        $r = AddressTags::resolve($address, self::KNOWN);
        $this->assertSame($unknown, $r['unknown']);
        $this->assertSame([], $r['tags']);
    }

    public function testMappedDeviceReplacesTheDevicePart(): void
    {
        // typo srv17 mapped to srv01: the rest of the address is still read
        $r = AddressTags::resolve('srv17_plesk_globex@x', self::KNOWN, 'globex.srv01');
        $this->assertSame(['kunde:globex', 'source:plesk', 'geraet:globex.srv01'], $r['tags']);
        $this->assertSame('globex.srv01', $r['device']);

        // an address outside the scheme is resolved by the mapping alone
        $r = AddressTags::resolve('statuscake.globex@x', self::KNOWN, 'globex.srv01');
        $this->assertSame(['tags' => [], 'device' => 'globex.srv01', 'unknown' => []], $r);

        // other unknown parts stay unknown
        $r = AddressTags::resolve('srv17_plsk_globex@x', self::KNOWN, 'globex.srv01');
        $this->assertSame([['part' => 'location_or_source', 'value' => 'plsk']], $r['unknown']);
    }

    public function testDeviceIsIdentifiedByCustomerAndCodeOnly(): void
    {
        $a = AddressTags::resolve('pm10_proxmox_2og.hq1_acme@x', self::KNOWN);
        $b = AddressTags::resolve('gc.pm10_hq1_acme@x', self::KNOWN);
        $this->assertSame('acme.pm10', $a['device']);
        $this->assertSame($a['device'], $b['device']);
    }
}
