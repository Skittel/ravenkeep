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

    /** Maximum number of spool entries imported per node and run. */
    public int $collectBatchSize = 500;
}
