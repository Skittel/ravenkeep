<?php

use App\Libraries\Collector\Collector;
use App\Libraries\Collector\MailrunnerSpoolTransport;
use App\Libraries\Mailrunner\MailrunnerClient;
use App\Libraries\Senders\SenderRules;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Fake MailRunner node: answers the API calls from an in-memory message list.
 */
final class FakeMailrunnerClient extends MailrunnerClient
{
    /** @var list<string> */
    public array $acked = [];

    /** @param array<string, array{message: array, raw: ?string}> $messages */
    public function __construct(public array $messages)
    {
        parent::__construct('https://node.example', 'mrt_test.secret');
    }

    protected function request(string $method, string $path, ?array $body = null): array
    {
        if ($method === 'POST' && $path === '/v1/messages/ack') {
            $known       = array_values(array_intersect($body['message_ids'], array_keys($this->messages)));
            $this->acked = array_merge($this->acked, $known);

            return [200, json_encode(['acked' => $known, 'unknown' => array_values(array_diff($body['message_ids'], $known))])];
        }
        if (preg_match('#^/v1/messages/([^/]+)/raw$#', $path, $m)) {
            $raw = $this->messages[$m[1]]['raw'] ?? null;

            return $raw === null ? [404, '{"error":{"code":"RAW_NOT_AVAILABLE"}}'] : [200, $raw];
        }
        if (str_starts_with($path, '/v1/messages?')) {
            parse_str(substr($path, strlen('/v1/messages?')), $q);
            $open = [];
            foreach ($this->messages as $id => $entry) {
                if (! in_array($id, $this->acked, true)) {
                    $open[] = $entry['message'];
                }
            }
            $page = array_slice($open, (int) $q['after_id'], (int) $q['limit']);

            return [200, json_encode(['data' => $page, 'next_after_id' => (int) $q['after_id'] + count($page),
                'has_more' => count($page) === (int) $q['limit']])];
        }

        return [404, '{"error":{"code":"NOT_FOUND"}}'];
    }
}

/**
 * @internal
 */
final class MailrunnerTransportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $archive;
    private array $node;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archive = sys_get_temp_dir() . '/rk-test-' . bin2hex(random_bytes(4)) . '/archive';
        mkdir($this->archive, 0700, true);
        $this->db->table('nodes')->insert(['name' => 'mr01', 'hostname' => 'mr01.example', 'transport' => 'mailrunner']);
        $this->node = ['id' => $this->db->insertID(), 'name' => 'mr01'];
    }

    private function message(string $id, array $debug = [], string $from = 'monitor@b.example'): array
    {
        $debug += [
            'header' => ['from' => [['address' => $from, 'display_name' => 'Monitor']], 'message_id' => "<{$id}@b.example>"],
            'spf'    => ['result' => 'pass', 'identity' => 'mailfrom', 'domain' => 'b.example', 'aligned' => true],
            'dkim'   => ['result' => 'none', 'signatures' => []],
            'dmarc'  => ['result' => 'pass', 'from_domain' => 'b.example', 'published_policy' => 'reject', 'aspf' => 'r', 'adkim' => 'r'],
            'fcrdns' => ['ptr' => ['mail.b.example']],
        ];

        return ['message' => [
            'message_id'  => $id,
            'profile'     => 'checked_auth',
            'role'        => null,
            'received_at' => '2026-11-04T20:00:00Z',
            'envelope'    => ['mail_from' => 'bounce@b.example', 'rcpt' => 'nas01@customer.example', 'client_ip' => '192.0.2.10', 'helo' => 'mail.b.example', 'queue_id' => 'ABC123'],
            'header'      => ['from' => "Monitor <{$from}>", 'to' => 'nas01@customer.example', 'subject' => 'Backup OK', 'date' => 'Wed, 04 Nov 2026 21:00:00 +0100'],
            'size'        => 42,
            'raw_available' => true,
            'debug'       => $debug,
        ], 'raw' => "From: Monitor <{$from}>\r\nSubject: Backup OK\r\n\r\nbody\r\n"];
    }

    /** @param list<array{pattern: string, target: string, action: string}> $rules */
    private function collect(FakeMailrunnerClient $client, array $rules = [], array $credentials = []): array
    {
        $collector = new Collector($this->db, $this->archive);

        return [$collector->collectNode($this->node, new MailrunnerSpoolTransport($client, new SenderRules($rules), $credentials)), $collector];
    }

    public function testMessageWithSmtpLoginIsAcceptedAndMappedToTheLogin(): void
    {
        $known   = $this->message('mrm_auth1', [], 'whatever@nowhere.example');
        $unknown = $this->message('mrm_auth2');
        foreach ([[&$known, 'mrc_known'], [&$unknown, 'mrc_other']] as [&$m, $credential]) {
            $m['message']['received_via'] = 'auth';
            $m['message']['auth']         = ['credential_id' => $credential, 'service' => 'submission'];
        }
        unset($m);
        $client = new FakeMailrunnerClient(['mrm_auth1' => $known, 'mrm_auth2' => $unknown]);

        // no sender rule at all: a login does not need one
        $this->collect($client, [], ['mrc_known' => 'pm10_ipmi_hq1_acme@customer.example']);

        $this->seeInDatabase('messages', ['spool_id' => 'mrm_auth1', 'mode' => 'auth', 'status' => 'accepted',
            'sasl_username' => 'pm10_ipmi_hq1_acme@customer.example', 'list_match_pattern' => null]);
        $this->seeInDatabase('messages', ['spool_id' => 'mrm_auth2', 'mode' => 'auth', 'sasl_username' => 'mrc_other']);
    }

    public function testImportsMessageArchivesRawAndAcknowledges(): void
    {
        $client = new FakeMailrunnerClient(['mrm_aaaa' => $this->message('mrm_aaaa')]);

        [$result] = $this->collect($client, [['pattern' => 'b.example', 'target' => 'header', 'action' => 'allow']]);

        $this->assertSame(['imported' => 1, 'duplicates' => 0, 'errors' => 0], $result);
        $this->seeInDatabase('messages', [
            'spool_id' => 'mrm_aaaa', 'status' => 'accepted', 'auth_verdict' => 'accepted', 'mode' => 'mx',
            'envelope_sender' => 'bounce@b.example', 'header_from_address' => 'monitor@b.example',
            'client_ip' => '192.0.2.10', 'client_ptr' => 'mail.b.example', 'received_at' => '2026-11-04 20:00:00',
            'list_match_pattern' => 'b.example', 'queue_id' => 'ABC123',
        ]);
        $this->assertFileExists("{$this->archive}/2026/11/04/mrm_aaaa.eml");
        $this->assertSame(['mrm_aaaa'], $client->acked);
    }

    public function testUnknownSenderIsPendingAndFailedAlignmentIsRejected(): void
    {
        $unaligned = ['spf' => ['result' => 'pass', 'identity' => 'mailfrom', 'domain' => 'relay.example', 'aligned' => false]];
        $client    = new FakeMailrunnerClient([
            'mrm_pend' => $this->message('mrm_pend', [], 'x@unknown.example'),
            'mrm_rej'  => $this->message('mrm_rej', $unaligned),
        ]);

        $this->collect($client, [['pattern' => 'b.example', 'target' => 'header', 'action' => 'allow']]);

        $this->seeInDatabase('messages', ['spool_id' => 'mrm_pend', 'status' => 'pending']);
        $this->seeInDatabase('messages', ['spool_id' => 'mrm_rej', 'status' => 'rejected', 'auth_verdict' => 'rejected']);
        $reasons = json_decode($this->db->table('messages')->where('spool_id', 'mrm_rej')->get()->getRow()->reasons, true);
        $this->assertContains('SPF pass for relay.example, but not aligned with From domain b.example', $reasons);
    }

    public function testAlignedDkimIsEnoughAndBlockRuleWins(): void
    {
        $dkim   = ['spf' => ['result' => 'softfail', 'identity' => 'mailfrom', 'domain' => 'b.example', 'aligned' => true],
            'dkim' => ['result' => 'pass', 'signatures' => [['result' => 'pass', 'domain' => 'b.example', 'selector' => 's1', 'aligned_with_from' => true, 'reason' => 'signature verified']]]];
        $client = new FakeMailrunnerClient([
            'mrm_dkim'  => $this->message('mrm_dkim', $dkim),
            'mrm_block' => $this->message('mrm_block', [], 'spam@bad.example'),
        ]);

        $this->collect($client, [
            ['pattern' => 'b.example', 'target' => 'header', 'action' => 'allow'],
            ['pattern' => 'bad.example', 'target' => 'header', 'action' => 'block'],
        ]);

        $this->seeInDatabase('messages', ['spool_id' => 'mrm_dkim', 'status' => 'accepted']);
        $this->seeInDatabase('messages', ['spool_id' => 'mrm_block', 'status' => 'blocked']);
    }

    public function testMissingRawIsAnErrorAndMessageStaysUnacknowledged(): void
    {
        $entry        = $this->message('mrm_noraw');
        $entry['raw'] = null;
        $client       = new FakeMailrunnerClient(['mrm_noraw' => $entry]);

        [$result, $collector] = $this->collect($client);

        $this->assertSame(['imported' => 0, 'duplicates' => 0, 'errors' => 1], $result);
        $this->assertStringContainsString('EML file missing', $collector->errors()[0]);
        $this->assertSame([], $client->acked);
        $this->dontSeeInDatabase('messages', ['spool_id' => 'mrm_noraw']);
    }

    public function testAlreadyImportedMessageIsOnlyAcknowledged(): void
    {
        $client = new FakeMailrunnerClient(['mrm_dup' => $this->message('mrm_dup')]);
        $this->collect($client);
        $client->acked = []; // simulate a crash after the database insert, before the ack

        [$result] = $this->collect($client);

        $this->assertSame(['imported' => 0, 'duplicates' => 1, 'errors' => 0], $result);
        $this->assertSame(['mrm_dup'], $client->acked);
        $this->assertSame(1, $this->db->table('messages')->where('spool_id', 'mrm_dup')->countAllResults());
    }

    public function testCollectsSeveralPages(): void
    {
        $messages = [];
        for ($i = 0; $i < 150; $i++) {
            $id            = sprintf('mrm_%04d', $i);
            $messages[$id] = $this->message($id);
        }
        $client = new FakeMailrunnerClient($messages);

        [$result] = $this->collect($client);

        $this->assertSame(150, $result['imported']);
        $this->assertCount(150, $client->acked);
    }
}
