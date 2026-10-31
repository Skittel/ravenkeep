<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * @internal
 */
final class PurgeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    public function testDeletesOnlyMessagesOlderThanTheirStatusRetention(): void
    {
        $archive = sys_get_temp_dir() . '/rk-purge-' . bin2hex(random_bytes(4));
        mkdir($archive, 0700, true);
        config('Ravenkeep')->archivePath = $archive;

        $this->db->table('nodes')->insert(['name' => 'test01', 'hostname' => 'test01.example']);
        $nodeId = $this->db->insertID();
        $rows   = [
            'pending-old'  => ['pending', 40],
            'pending-new'  => ['pending', 10],
            'accepted-40'  => ['accepted', 40],
            'accepted-400' => ['accepted', 400],
        ];
        foreach ($rows as $id => [$status, $days]) {
            file_put_contents("{$archive}/{$id}.eml", 'x');
            $this->db->table('messages')->insert(['node_id' => $nodeId, 'spool_id' => $id, 'status' => $status, 'mode' => 'mx',
                'received_at' => gmdate('Y-m-d H:i:s', time() - $days * 86400), 'envelope_recipient' => 'x@customer.ravenkeep.cloud',
                'eml_path' => "{$id}.eml", 'eml_sha256' => str_repeat('0', 64)]);
        }

        command('ravenkeep:purge --dry-run');
        $this->seeNumRecords(4, 'messages', []);

        command('ravenkeep:purge');
        $remaining = array_column($this->db->table('messages')->select('spool_id')->orderBy('spool_id')->get()->getResultArray(), 'spool_id');
        $this->assertSame(['accepted-40', 'pending-new'], $remaining);
        $this->assertFileDoesNotExist("{$archive}/pending-old.eml");
        $this->assertFileDoesNotExist("{$archive}/accepted-400.eml");
        $this->assertFileExists("{$archive}/accepted-40.eml");
    }
}
