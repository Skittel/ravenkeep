<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Messages::index');
$routes->get('messages/(:num)', 'Messages::show/$1');
$routes->get('messages/(:num)/html', 'Messages::html/$1');
$routes->get('messages/(:num)/eml', 'Messages::eml/$1');
