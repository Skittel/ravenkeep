<?php

use App\Libraries\Charset;
use App\Libraries\MessageReader;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Text labelled Latin but sent as UTF-8 (T-29).
 *
 * @internal
 */
final class CharsetTest extends CIUnitTestCase
{
    /** UTF-8 bytes read as ISO-8859-1, as a decoder following the label produces them */
    private static function asLatin(string $utf8): string
    {
        return mb_convert_encoding($utf8, 'UTF-8', 'ISO-8859-1');
    }

    public function testRepairsMislabelledHeader(): void
    {
        $raw     = '=?iso-8859-1?Q?Acme,_HQ,_NB-01_=E2=80=93_Patch_fehlgeschlagen_f=C3=BCr_Ger=C3=A4t?=';
        $decoded = self::asLatin('Acme, HQ, NB-01 – Patch fehlgeschlagen für Gerät');
        $this->assertNotSame('Acme, HQ, NB-01 – Patch fehlgeschlagen für Gerät', $decoded);
        $this->assertSame('Acme, HQ, NB-01 – Patch fehlgeschlagen für Gerät', Charset::repairHeader($decoded, $raw));
        // Windows-1252 label: 0x80 was decoded as "€"
        $this->assertSame('a – b', Charset::repairHeader(mb_convert_encoding('a – b', 'UTF-8', 'Windows-1252'), '=?windows-1252?Q?a_=E2=80=93_b?='));
    }

    public function testRepairsMixedLatinAndUtf8(): void
    {
        // Latin-1 "ä" (=E4) next to a UTF-8 dash (=E2=80=93)
        $raw     = '=?iso-8859-1?Q?Acme,_Mobile_Ger=E4te,_NB-01_=E2=80=93_Patch?=';
        $decoded = mb_convert_encoding("Acme, Mobile Ger\xE4te, NB-01 \xE2\x80\x93 Patch", 'UTF-8', 'ISO-8859-1');
        $this->assertSame('Acme, Mobile Geräte, NB-01 – Patch', Charset::repairHeader($decoded, $raw));
        $this->assertSame('Geräte – ok', Charset::mislabelledBody("Ger\xE4te \xE2\x80\x93 ok", 'iso-8859-1'));
        $this->assertNull(Charset::mislabelledBody("Ger\xE4te", 'iso-8859-1')); // pure Latin-1: decode by label
    }

    public function testLeavesCorrectTextAlone(): void
    {
        // correctly labelled Latin-1 umlaut ("für" = f=FCr): not valid UTF-8 as bytes
        $this->assertSame('Bericht für Acme', Charset::repairHeader('Bericht für Acme', '=?iso-8859-1?Q?Bericht_f=FCr_Acme?='));
        // UTF-8 label: never touched, even if the text looks odd
        $odd = self::asLatin('Gerät');
        $this->assertSame($odd, Charset::repairHeader($odd, '=?utf-8?Q?Ger=C3=83=C2=A4t?='));
        // no encoded word (plain ASCII header)
        $this->assertSame('Plain subject', Charset::repairHeader('Plain subject', 'Plain subject'));
        $this->assertNull(Charset::repairHeader(null, ''));
    }

    public function testRawHeaderIsUnfolded(): void
    {
        $eml = "From: a@example.org\r\nSubject: =?iso-8859-1?Q?part1?=\r\n =?iso-8859-1?Q?part2?=\r\nTo: b@example.org\r\n\r\nSubject: not a header\r\n";
        $this->assertSame('=?iso-8859-1?Q?part1?= =?iso-8859-1?Q?part2?=', Charset::rawHeader($eml, 'subject'));
        $this->assertSame('', Charset::rawHeader($eml, 'Date'));
    }

    public function testReaderUsesUtf8BodyDespiteLatinLabel(): void
    {
        $dir = sys_get_temp_dir() . '/rk-charset-' . getmypid();
        @mkdir($dir);
        $body = quoted_printable_encode('Auf Gerät NB-01 – nicht installiert');
        file_put_contents("{$dir}/a.eml", "From: a@example.org\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=iso-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n{$body}\r\n");
        file_put_contents("{$dir}/b.eml", "From: a@example.org\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=iso-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nAuf Ger=E4t NB-01\r\n");
        try {
            $this->assertSame('Auf Gerät NB-01 – nicht installiert', trim((string) (new MessageReader($dir, 'a.eml'))->textBody()));
            $this->assertSame('Auf Gerät NB-01', trim((string) (new MessageReader($dir, 'b.eml'))->textBody())); // real Latin-1
        } finally {
            @unlink("{$dir}/a.eml");
            @unlink("{$dir}/b.eml");
            @rmdir($dir);
        }
    }
}
