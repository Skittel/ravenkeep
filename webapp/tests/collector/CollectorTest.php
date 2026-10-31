<?php

use App\Libraries\Collector\Collector;
use App\Libraries\Collector\LocalSpoolTransport;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class CollectorTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $spool;
    private string $archive;
    private array $node;

    protected function setUp(): void
    {
        parent::setUp();
        $base          = sys_get_temp_dir() . '/rk-test-' . bin2hex(random_bytes(4));
        $this->spool   = "{$base}/spool";
        $this->archive = "{$base}/archive";
        mkdir("{$this->spool}/new", 0700, true);
        mkdir($this->archive, 0700, true);

        $this->db->table('nodes')->insert(['name' => 'test01', 'hostname' => 'test01.example', 'spool_path' => $this->spool]);
        $this->node = ['id' => $this->db->insertID(), 'name' => 'test01'];
    }

    private function addEntry(string $id, array $meta = [], ?string $eml = "From: a@b.example\r\nSubject: Hi\r\n\r\nbody\r\n"): void
    {
        $meta += [
            'id'          => $id,
            'received_at' => '2026-10-31T21:23:40+0100',
            'mode'        => 'mx',
            'status'      => 'pending',
            'envelope'    => ['sender' => 'bounce@b.example', 'recipient' => 'nas01@customer.ravenkeep.cloud'],
            'client'      => ['address' => 'IPv6:2001:db8::1', 'hostname' => 'mail.b.example', 'ptr' => ['mail.b.example'], 'helo' => 'mail.b.example'],
            'headers'     => ['from' => 'Monitor <a@b.example>', 'from_address' => 'a@b.example', 'subject' => 'Prüfung'],
            'reasons'     => ['SPF none for b.example'],
        ];
        if ($eml !== null) {
            file_put_contents("{$this->spool}/new/{$id}.eml", $eml);
        }
        file_put_contents("{$this->spool}/new/{$id}.json", json_encode($meta));
    }

    private function collect(): array
    {
        $collector = new Collector($this->db, $this->archive);

        return [$collector->collectNode($this->node, new LocalSpoolTransport($this->spool)), $collector];
    }

    public function testImportsEntryIntoDatabaseAndArchiveAndClearsSpool(): void
    {
        $this->addEntry('20261031T202340-aaaaaaaaaaaa');

        [$result] = $this->collect();

        $this->assertSame(['imported' => 1, 'duplicates' => 0, 'errors' => 0], $result);
        $this->seeInDatabase('messages', [
            'spool_id'    => '20261031T202340-aaaaaaaaaaaa',
            'status'      => 'pending',
            'received_at' => '2026-10-31 20:23:40', // stored in UTC
            'client_ip'   => '2001:db8::1',         // "IPv6:" prefix removed
            'subject'     => 'Prüfung',
        ]);
        $this->assertFileExists("{$this->archive}/2026/10/31/20261031T202340-aaaaaaaaaaaa.eml");
        $this->assertSame([], glob("{$this->spool}/new/*"));
    }

    public function testEntryImportedTwiceIsOnlyStoredOnce(): void
    {
        $this->addEntry('20261031T202340-bbbbbbbbbbbb');
        $this->collect();
        // The node could not delete the files before a crash: the same entry appears again
        $this->addEntry('20261031T202340-bbbbbbbbbbbb');

        [$result] = $this->collect();

        $this->assertSame(['imported' => 0, 'duplicates' => 1, 'errors' => 0], $result);
        $this->seeNumRecords(1, 'messages', ['spool_id' => '20261031T202340-bbbbbbbbbbbb']);
        $this->assertSame([], glob("{$this->spool}/new/*"));
    }

    public function testEntryWithoutEmlIsKeptAndReported(): void
    {
        $this->addEntry('20261031T202340-cccccccccccc', [], null);

        [$result, $collector] = $this->collect();

        $this->assertSame(1, $result['errors']);
        $this->assertStringContainsString('EML file missing', $collector->errors()[0]);
        $this->assertFileExists("{$this->spool}/new/20261031T202340-cccccccccccc.json");
        $this->dontSeeInDatabase('messages', ['spool_id' => '20261031T202340-cccccccccccc']);
    }

    public function testLegacyVerdictFieldIsMapped(): void
    {
        $this->addEntry('20261031T202340-dddddddddddd', ['status' => null, 'mode' => 'auth', 'verdict' => 'accepted']);

        $this->collect();

        $this->seeInDatabase('messages', ['spool_id' => '20261031T202340-dddddddddddd', 'status' => 'accepted']);
    }
}
