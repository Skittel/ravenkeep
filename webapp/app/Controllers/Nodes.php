<?php

namespace App\Controllers;

use App\Libraries\Nodes\NodeConfigSync;

/**
 * MX/SMTP nodes: last collection and config sync status.
 */
class Nodes extends BaseController
{
    protected $helpers = ['ravenkeep', 'url'];

    public function index(): string
    {
        $db   = db_connect();
        $sync = new NodeConfigSync($db);
        $sync->refreshStatus();

        return view('nodes/index', [
            'nodes'       => $db->table('nodes')->orderBy('name')->get()->getResultArray(),
            'currentHash' => $sync->bundle()['hash'],
        ]);
    }
}
