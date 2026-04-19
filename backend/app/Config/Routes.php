<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

$routes->group('auth', static function ($routes) {
	$routes->post('login', 'Auth::login');
	$routes->get('getUserInfo', 'Auth::getUserInfo');
	$routes->post('refreshToken', 'Auth::refreshToken');
});
