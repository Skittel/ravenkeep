<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class MessagesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private int $acceptedId;
    private int $pendingId;

    protected function setUp(): void
    {
        parent::setUp();
        $archive = sys_get_temp_dir() . '/rk-ui-' . bin2hex(random_bytes(4));
        mkdir("{$archive}/2026/10/31", 0700, true);
        config('Ravenkeep')->archivePath = $archive;

        file_put_contents("{$archive}/2026/10/31/a.eml",
            "From: Monitor <alert@monitor.example>\r\nTo: x@customer.ravenkeep.cloud\r\nSubject: Backup OK\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=b\r\n\r\n"
            . "--b\r\nContent-Type: text/plain\r\n\r\nplain body\r\n"
            . "--b\r\nContent-Type: text/html\r\n\r\n<p>html body</p><script>alert(1)</script>\r\n--b--\r\n");

        $this->db->table('nodes')->insert(['name' => 'test01', 'hostname' => 'test01.example']);
        $nodeId = $this->db->insertID();
        $base   = ['node_id' => $nodeId, 'received_at' => '2026-10-31 12:00:00', 'mode' => 'mx',
            'envelope_recipient' => 'x@customer.ravenkeep.cloud', 'eml_path' => '2026/10/31/a.eml', 'eml_sha256' => str_repeat('0', 64)];

        $this->db->table('messages')->insert($base + ['spool_id' => 'a', 'status' => 'accepted', 'subject' => 'Backup OK',
            'envelope_sender' => 'bounce@monitor.example', 'reasons' => '[]']);
        $this->acceptedId = $this->db->insertID();
        $this->db->table('messages')->insert($base + ['spool_id' => 'b', 'status' => 'pending', 'subject' => 'Unknown <b>sender</b>',
            'envelope_sender' => 'spam@unknown.example', 'reasons' => '["SPF none for unknown.example"]']);
        $this->pendingId = $this->db->insertID();
    }

    public function testListShowsMessagesAndEscapesSubject(): void
    {
        $result = $this->get('/');

        $result->assertOK();
        $result->assertSee('Backup OK');
        $result->assertSee('Unknown &lt;b&gt;sender&lt;/b&gt;');
        $result->assertHeader('Content-Security-Policy');
    }

    public function testStatusFilterAndSearch(): void
    {
        $pending = $this->get('/?status=pending');
        $pending->assertSee('spam@unknown.example');
        $pending->assertDontSee('Backup OK');

        $search = $this->get('/?q=monitor');
        $search->assertSee('Backup OK');
        $search->assertDontSee('spam@unknown.example');
    }

    public function testReadingPaneShowsReasonsAndBody(): void
    {
        $result = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get("messages/{$this->pendingId}");

        $result->assertOK();
        $result->assertSee('SPF none for unknown.example');
        $result->assertSee('plain body');
    }

    public function testHtmlViewIsSandboxed(): void
    {
        $result = $this->get("messages/{$this->acceptedId}/html");

        $result->assertOK();
        $result->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:");
        $result->assertSee('html body');
    }

    public function testUnknownMessageIs404(): void
    {
        $this->expectException(CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get('messages/999999');
    }
}
