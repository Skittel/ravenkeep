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
$routes->get('rules', 'Rules::index');
$routes->get('rules/new', 'Rules::edit');
$routes->post('rules', 'Rules::save');
$routes->get('rules/(:num)', 'Rules::edit/$1');
$routes->post('rules/(:num)', 'Rules::save/$1');
$routes->post('rules/(:num)/delete', 'Rules::delete/$1');
$routes->post('rules/(:num)/toggle', 'Rules::toggle/$1');
$routes->post('rules/(:num)/move/(up|down)', 'Rules::move/$1/$2');
$routes->post('messages/(:num)/tags', 'Messages::addTag/$1');
$routes->post('messages/(:num)/tags/(:num)/delete', 'Messages::removeTag/$1/$2');
$routes->get('devices', 'Devices::index');
$routes->post('devices', 'Devices::create');
$routes->post('devices/(:num)/password', 'Devices::password/$1');
$routes->post('devices/(:num)/toggle', 'Devices::toggle/$1');
$routes->post('devices/(:num)/delete', 'Devices::delete/$1');
$routes->get('settings', 'Settings::index');
$routes->post('settings/domains', 'Settings::addDomain');
$routes->post('settings/domains/(:num)/delete', 'Settings::deleteDomain/$1');
$routes->post('settings/networks', 'Settings::addNetwork');
$routes->post('settings/networks/(:num)/delete', 'Settings::deleteNetwork/$1');
