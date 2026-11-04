<?php

use App\Libraries\Rules\AddressTags;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Address scheme (E-016): device[_type][_location]_customer, "." for levels, read from the right.
 *
 * @internal
 */
final class AddressTagsTest extends CIUnitTestCase
{
    public static function addresses(): array
    {
        return [
            'source of a customer' => ['n-sight_skit@report.example', ['kunde:skit', 'geraet:skit.n-sight']],
            'device at a location' => ['nas05_hb_atc@report.example', ['kunde:atc', 'standort:atc.hb', 'geraet:atc.nas05']],
            'full example'         => ['gc.pm2610_proxmox_2og.hb_atc@report.example', [
                'kunde:atc', 'standort:atc.hb', 'standort:atc.hb.2og', 'typ:proxmox', 'geraet:atc.pm2610', 'meldung:gc']],
            'subtype and room'     => ['pm2601_ve.proxmox_r12.2og.hb_atc@x', [
                'kunde:atc', 'standort:atc.hb', 'standort:atc.hb.2og', 'standort:atc.hb.2og.r12', 'typ:proxmox', 'typ:proxmox.ve', 'geraet:atc.pm2601']],
            'upper case'           => ['NAS05_HB_ATC@report.example', ['kunde:atc', 'standort:atc.hb', 'geraet:atc.nas05']],
            'hyphens in names'     => ['hyper-v01_hyper-v_hb_atc@x', ['kunde:atc', 'standort:atc.hb', 'typ:hyper-v', 'geraet:atc.hyper-v01']],
            'one group'            => ['test01@customer.example', []],
            'five groups'          => ['a_b_c_d_e@x', []],
            'empty group'          => ['nas05__atc@x', []],
            'dotted customer'      => ['nas05_hb_x.atc@x', []],
            'invalid characters'   => ['nas+05_atc@x', []],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('addresses')]
    public function testTags(string $address, array $expected): void
    {
        $this->assertSame($expected, AddressTags::tags($address));
    }

    public function testDeviceIsIdentifiedByCustomerAndCodeOnly(): void
    {
        $a = AddressTags::parse('pm2610_proxmox_2og.hb_atc@x');
        $b = AddressTags::parse('gc.pm2610_hb_atc@x');
        $this->assertSame([$a['customer'], $a['device']], [$b['customer'], $b['device']]);
    }
}
