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
        $base   = ['node_id' => $nodeId, 'received_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'mode' => 'mx',
            'envelope_recipient' => 'x@customer.ravenkeep.cloud', 'eml_path' => '2026/10/31/a.eml', 'eml_sha256' => str_repeat('0', 64)];

        $this->db->table('messages')->insert($base + ['spool_id' => 'a', 'status' => 'accepted', 'subject' => 'Backup OK',
            'envelope_sender' => 'bounce@monitor.example', 'reasons' => '[]']);
        $this->acceptedId = $this->db->insertID();
        $this->db->table('messages')->insert($base + ['spool_id' => 'b', 'status' => 'pending', 'subject' => 'Unknown <b>sender</b>',
            'envelope_sender' => 'spam@unknown.example', 'reasons' => '["SPF none for unknown.example"]']);
        $this->pendingId = $this->db->insertID();

        $this->withSession(['user' => ['oid' => 'o', 'name' => 'Test', 'username' => 't@example.org', 'role' => 'ro', 'login_at' => time()]]);
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
        $pending->assertSee('Unknown &lt;b&gt;sender&lt;/b&gt;');
        $pending->assertDontSee('Backup OK');

        $search = $this->get('/?q=monitor');
        $search->assertSee('Backup OK');
        $search->assertDontSee('Unknown &lt;b&gt;sender&lt;/b&gt;');
    }

    public function testTimeRangeDefaultsTo48Hours(): void
    {
        $this->db->table('messages')->insert(['node_id' => 1, 'spool_id' => 'old', 'status' => 'accepted', 'subject' => 'Old report',
            'received_at' => gmdate('Y-m-d H:i:s', time() - 3 * 86400), 'mode' => 'mx', 'envelope_recipient' => 'x@customer.ravenkeep.cloud',
            'envelope_sender' => 'a@monitor.example', 'eml_path' => '2026/10/31/a.eml', 'eml_sha256' => str_repeat('0', 64), 'reasons' => '[]']);

        $default = $this->get('/');
        $default->assertSee('Backup OK');
        $default->assertDontSee('Old report');
        $this->assertStringContainsString('<option value="48h" selected>', $default->getBody());

        $this->get('/?range=1w')->assertSee('Old report');
        $this->get('/?range=all')->assertSee('Old report');
        $this->get('/?range=1h')->assertDontSee('Old report');
        $this->get('/?range=bogus')->assertDontSee('Old report'); // invalid = default
        // the selected range is kept by the filter links
        $this->assertStringContainsString('range=1w', $this->get('/?range=1w')->getBody());
    }

    public function testReadingPaneShowsReasonsAndBody(): void
    {
        $result = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get("messages/{$this->pendingId}");

        $result->assertOK();
        $result->assertSee('SPF none for unknown.example');
        $result->assertSee('plain body');
    }

    public function testReadingPaneShowsSummaryAndTabs(): void
    {
        $this->db->table('messages')->where('id', $this->acceptedId)->update(['header_from' => 'Monitor <alert@monitor.example>', 'header_to' => 'x@customer.ravenkeep.cloud']);
        $result = $this->get('/?id=' . $this->acceptedId);
        $result->assertOK();
        foreach (['Monitor &lt;alert@monitor.example&gt;', 'data-tab="details"', 'data-tab="rules"', 'data-tab="headers"'] as $text) {
            $result->assertSee($text);
        }
        if (getenv('RK_DUMP_PAGES')) {
            file_put_contents(getenv('RK_DUMP_PAGES') . '/mail.html', $result->getBody());
            file_put_contents(getenv('RK_DUMP_PAGES') . '/dashboard.html', $this->get('dashboard')->getBody());
            file_put_contents(getenv('RK_DUMP_PAGES') . '/list.html', $this->get('/')->getBody());
        }
    }

    public function testListShowsCustomerSourceTagsAndStatusInsteadOfAddresses(): void
    {
        $runner = new App\Libraries\Rules\RuleRunner($this->db, config('Ravenkeep')->archivePath);
        foreach (['kunde:acme', 'source:proxmox', 'source:proxmox.ve', 'geraet:acme.pm10', 'meldung:gc'] as $tag) {
            $this->db->table('message_tags')->insert(['message_id' => $this->acceptedId, 'tag_id' => $runner->tagId($tag), 'source' => 'address']);
        }
        $body = $this->get('/')->getBody();

        $this->assertStringContainsString('<td class="col-customer" title="acme">acme</td>', $body);
        // only the most specific level of a source
        $this->assertStringContainsString('<td class="col-source" title="proxmox.ve">proxmox.ve</td>', $body);
        $this->assertStringContainsString('title="geraet - acme.pm10">acme.pm10</span>', $body);
        $this->assertStringNotContainsString('bounce@monitor.example', $body);
        $this->assertStringNotContainsString('x@customer.ravenkeep.cloud', $body);
        $this->assertStringContainsString('work-rating rating-none">unbewertet', $body);
        $this->assertStringContainsString('handling-new">neu', $body);
    }

    public function testPaneHidesAcceptedBadgeButShowsDifferentStatus(): void
    {
        $accepted = $this->get('/?id=' . $this->acceptedId)->getBody();
        $this->assertStringNotContainsString('badge badge-accepted', $accepted);
        $this->assertStringContainsString('data-tab="notes"', $accepted);
        $this->assertStringContainsString('unbewertet', $accepted);

        $this->assertStringContainsString('badge badge-pending', $this->get('/?id=' . $this->pendingId)->getBody());
    }

    public function testHandlingStatus(): void
    {
        config('Filters')->globals['before'] = ['rklogin' => ['except' => ['auth/*']]]; // CSRF is covered by AuthTest / the running app
        $this->withSession(['user' => ['oid' => 'o1', 'name' => 'Erika Muster', 'username' => 'e@example.org', 'role' => 'rw', 'login_at' => time()]]);

        $this->post("messages/{$this->acceptedId}/handling", ['handling' => 'done'])->assertRedirect();
        $row = $this->db->table('messages')->where('id', $this->acceptedId)->get()->getRowArray();
        $this->assertSame(['done', null, 'Erika Muster'], [$row['handling'], $row['handling_until'], $row['handling_by']]);

        // waiting needs a date in the future
        $this->post("messages/{$this->acceptedId}/handling", ['handling' => 'waiting', 'handling_until' => '2020-01-01']);
        $this->assertSame('done', $this->db->table('messages')->where('id', $this->acceptedId)->get()->getRow()->handling);
        $this->post("messages/{$this->acceptedId}/handling", ['handling' => 'bogus']);
        $this->assertSame('done', $this->db->table('messages')->where('id', $this->acceptedId)->get()->getRow()->handling);

        $until = gmdate('Y-m-d', time() + 5 * 86400);
        $this->post("messages/{$this->acceptedId}/handling", ['handling' => 'waiting', 'handling_until' => $until]);
        $row = $this->db->table('messages')->where('id', $this->acceptedId)->get()->getRowArray();
        $this->assertSame(['waiting', $until], [$row['handling'], $row['handling_until']]);
        $this->assertStringContainsString('warten bis ' . date('d.m.', strtotime($until)), $this->get('/')->getBody());
    }

    public function testNotesAreAppendedWithAuthorAndNeverChanged(): void
    {
        config('Filters')->globals['before'] = ['rklogin' => ['except' => ['auth/*']]]; // CSRF is covered by AuthTest / the running app
        $this->withSession(['user' => ['oid' => 'o1', 'name' => 'Erika Muster', 'username' => 'e@example.org', 'role' => 'rw', 'login_at' => time()]]);

        $this->post("messages/{$this->acceptedId}/notes", ['body' => 'first <b>note</b>'])->assertRedirectTo(site_url('/') . '?id=' . $this->acceptedId . '&tab=notes');
        $this->post("messages/{$this->acceptedId}/notes", ['body' => "second\nline"]);
        $this->post("messages/{$this->acceptedId}/notes", ['body' => '   ']); // empty: ignored
        $this->db->table('message_notes')->where('body', 'first <b>note</b>')->update(['created_at' => gmdate('Y-m-d H:i:s', time() - 2 * 86400)]);

        $notes = $this->db->table('message_notes')->where('message_id', $this->acceptedId)->orderBy('id')->get()->getResultArray();
        $this->assertCount(2, $notes);
        $this->assertSame(['Erika Muster', 'e@example.org'], [$notes[0]['author_name'], $notes[0]['author_username']]);

        $body = $this->get('/?id=' . $this->acceptedId . '&tab=notes')->getBody();
        $first  = strpos($body, 'first &lt;b&gt;note&lt;/b&gt;');
        $second = strpos($body, 'second');
        $this->assertNotFalse($first);
        $this->assertGreaterThan($first, $second); // newest at the bottom
        $this->assertStringContainsString('vor 2 Tagen', $body);
        $this->assertStringContainsString('gerade eben', $body);
        $this->assertStringContainsString('<button type="button" data-tab="notes" class="active">', $body);

        // read-only users cannot write notes
        $this->withSession(['user' => ['oid' => 'o', 'name' => 'Test', 'username' => 't@example.org', 'role' => 'ro', 'login_at' => time()]]);
        $this->post("messages/{$this->acceptedId}/notes", ['body' => 'ro']);
        $this->assertSame(2, $this->db->table('message_notes')->where('message_id', $this->acceptedId)->countAllResults());
    }

    public function testRelativeTime(): void
    {
        helper('ravenkeep');
        $now = strtotime('2026-11-06 12:00:00 UTC');
        $this->assertSame('gerade eben', rk_relative_time('2026-11-06 11:59:30', $now));
        $this->assertSame('vor 1 Minute', rk_relative_time('2026-11-06 11:59:00', $now));
        $this->assertSame('vor 5 Minuten', rk_relative_time('2026-11-06 11:55:00', $now));
        $this->assertSame('vor 3 Stunden', rk_relative_time('2026-11-06 09:00:00', $now));
        $this->assertSame('vor 2 Tagen', rk_relative_time('2026-11-04 12:00:00', $now));
        $this->assertSame('vor 1 Jahr', rk_relative_time('2025-11-01 12:00:00', $now));
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
