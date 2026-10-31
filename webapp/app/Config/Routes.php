<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Messages::index');
$routes->get('messages/(:num)', 'Messages::show/$1');
$routes->get('messages/(:num)/html', 'Messages::html/$1');
$routes->get('messages/(:num)/eml', 'Messages::eml/$1');
$routes->get('senders', 'Senders::index');
$routes->post('senders', 'Senders::save');
$routes->post('senders/(:num)/delete', 'Senders::delete/$1');
$routes->get('nodes', 'Nodes::index');
