<?php

namespace App\Controllers;

use App\Libraries\Mailrunner\CollectSignal;
use App\Libraries\Mailrunner\Webhook;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Ping from the MailRunner receive node (T-22). The only route without Entra sign-in: it carries
 * no data and only requests a normal collect run. Unknown values get a plain 404.
 */
class Hooks extends BaseController
{
    public function mailrunner(string $value): ResponseInterface
    {
        $webhook = new Webhook(db_connect(), CollectSignal::fromConfig(), config('App')->baseURL);

        return $webhook->ping($value)
            ? $this->response->setStatusCode(204)
            : $this->response->setStatusCode(404)->setBody('Not found');
    }
}
