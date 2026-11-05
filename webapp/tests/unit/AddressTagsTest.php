<?php

use App\Libraries\Rules\AddressTags;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Address scheme (E-016, E-018): the last group is the customer; with 2 groups the first one is a device
 * only if the customer has that device, with 3 groups the middle one is a location only if the customer
 * has that location, otherwise it is a source. "." separates levels, read from the right.
 *
 * @internal
 */
final class AddressTagsTest extends CIUnitTestCase
{
    private const KNOWN = [
        'locations' => ['acme.hq1', 'acme.br2'],
        'devices'   => ['acme.nas05', 'initech.fw01'],
    ];

    public static function addresses(): array
    {
        return [
            'source of a customer'    => ['n-sight_initech@report.example', ['kunde:initech', 'source:n-sight']],
            'known device, 2 groups'  => ['fw01_initech@report.example', ['kunde:initech', 'geraet:initech.fw01']],
            'source with sub-address' => ['gc.n-sight_initech@x', ['kunde:initech', 'source:n-sight', 'meldung:gc']],
            'device at a location'    => ['nas05_hq1_acme@report.example', ['kunde:acme', 'standort:acme.hq1', 'geraet:acme.nas05']],
            'device with a source'    => ['srv01_plesk_globex@report.example', ['kunde:globex', 'source:plesk', 'geraet:globex.srv01']],
            'location of another customer is a source' => ['nas05_hq1_globex@x', ['kunde:globex', 'source:hq1', 'geraet:globex.nas05']],
            'level of a known location' => ['nas05_2og.hq1_acme@x', ['kunde:acme', 'standort:acme.hq1', 'standort:acme.hq1.2og', 'geraet:acme.nas05']],
            'full example'            => ['gc.pm10_proxmox_2og.hq1_acme@report.example', [
                'kunde:acme', 'standort:acme.hq1', 'standort:acme.hq1.2og', 'source:proxmox', 'geraet:acme.pm10', 'meldung:gc']],
            '4 groups, unknown location stays a location' => ['pm01_ve.proxmox_r12.2og.hq_acme@x', [
                'kunde:acme', 'standort:acme.hq', 'standort:acme.hq.2og', 'standort:acme.hq.2og.r12', 'source:proxmox', 'source:proxmox.ve', 'geraet:acme.pm01']],
            'upper case'              => ['NAS05_HQ1_ACME@report.example', ['kunde:acme', 'standort:acme.hq1', 'geraet:acme.nas05']],
            'hyphens in names'        => ['hyper-v01_hyper-v_br2_acme@x', ['kunde:acme', 'standort:acme.br2', 'source:hyper-v', 'geraet:acme.hyper-v01']],
            'one group'               => ['test01@customer.example', []],
            'five groups'             => ['a_b_c_d_e@x', []],
            'empty group'             => ['nas05__acme@x', []],
            'dotted customer'         => ['nas05_hq_x.acme@x', []],
            'invalid characters'      => ['nas+05_acme@x', []],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('addresses')]
    public function testTags(string $address, array $expected): void
    {
        $this->assertSame($expected, AddressTags::tags($address, self::KNOWN));
    }

    public function testWithoutInventoryEverythingUnclearIsASource(): void
    {
        $this->assertSame(['kunde:acme', 'source:hq1', 'geraet:acme.nas05'], AddressTags::tags('nas05_hq1_acme@x'));
        $this->assertNull(AddressTags::parse('nas05_acme@x')['device']);
    }

    public function testDeviceIsIdentifiedByCustomerAndCodeOnly(): void
    {
        $a = AddressTags::parse('pm10_proxmox_2og.hq1_acme@x', self::KNOWN);
        $b = AddressTags::parse('gc.pm10_hq1_acme@x', self::KNOWN);
        $this->assertSame([$a['customer'], $a['device']], [$b['customer'], $b['device']]);
    }
}
