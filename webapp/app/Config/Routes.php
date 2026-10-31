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
