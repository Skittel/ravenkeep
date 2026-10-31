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

    /** Time zone used to display dates in the UI (dates are stored in UTC). */
    public string $displayTimezone = 'Europe/Berlin';

    /** Messages per page in the message list. */
    public int $pageSize = 50;

    /** Maximum number of spool entries imported per node and run. */
    public int $collectBatchSize = 500;
}
