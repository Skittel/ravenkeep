<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Ravenkeep application settings. Values can be overridden in .env, e.g.
 *   ravenkeep.archivePath = /var/lib/ravenkeep-app/archive
 */
class Ravenkeep extends BaseConfig
{
    /** Directory for original messages (EML), outside the web root. */
    public string $archivePath = '/var/lib/ravenkeep-app/archive';

    /** Host name devices use for SMTP submission (shown with new credentials). */
    public string $smtpHost = 'smtp.ravenkeep.cloud';

    /** Time zone used to display dates in the UI (dates are stored in UTC). */
    public string $displayTimezone = 'Europe/Berlin';

    /** Key for secrets Ravenkeep must read again (Secrets), outside the repository: 32 bytes base64. */
    public string $secretKeyFile = '/etc/ravenkeep/secret.key';

    /** Messages per page in the message list. */
    public int $pageSize = 50;

    /**
     * Retention in days per status (T-14, agreed 2026-10-31); messages and archived EML files
     * older than this are deleted by `php spark ravenkeep:purge` (daily timer).
     *
     * @var array<string, int>
     */
    public array $retentionDays = [
        'pending'  => 30,
        'blocked'  => 30,
        'accepted' => 365,
        'rejected' => 365,
    ];

    /** Maximum number of spool entries imported per node and run. */
    public int $collectBatchSize = 500;
}
