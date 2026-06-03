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
	$routes->post('logout', 'Auth::logout');
});

$routes->group('route', static function ($routes) {
	$routes->get('getUserRoutes', 'Route::getUserRoutes');
	$routes->get('getConstantRoutes', 'Route::getConstantRoutes');
	$routes->get('isRouteExist', 'Route::isRouteExist');
});

$routes->group('frame', static function ($routes) {
	$routes->get('init/(:segment)', 'Frame::init/$1');
	$routes->get('init/(:segment)/(:segment)', 'Frame::init/$1/$2');
	$routes->get('init/(:segment)/(:segment)/(:any)', 'Frame::init/$1/$2/$3');
	$routes->match(['get', 'post'], 'set_query_condition/(:segment)', 'Frame::set_query_condition/$1');
	$routes->match(['get', 'post'], 'set_sp_condition/(:segment)', 'Frame::set_sp_condition/$1');
	$routes->match(['get', 'post'], 'comment_add/(:segment)', 'Frame::comment_add/$1');
	$routes->match(['get', 'post'], 'update_row/(:segment)', 'Frame::update_row/$1');
	$routes->match(['get', 'post'], 'add_row/(:segment)', 'Frame::add_row/$1');
	$routes->match(['get', 'post'], 'delete_row/(:segment)', 'Frame::delete_row/$1');
	$routes->get('export/(:segment)', 'Frame::export/$1');
	$routes->get('export/(:segment)/(:any)', 'Frame::export/$1/$2');
	$routes->match(['get', 'post'], 'verify_popup/(:segment)', 'Frame::verify_popup/$1');
	$routes->match(['get', 'post'], 'upkeep/(:segment)', 'Frame::upkeep/$1');
	$routes->match(['get', 'post'], 'update_table/(:segment)', 'Frame::update_table/$1');
	$routes->match(['get', 'post'], 'chart_drill/(:segment)', 'Frame::chart_drill/$1');
	$routes->get('change_pswd', 'Frame::change_pswd');
	$routes->get('change_pswd/(:segment)', 'Frame::change_pswd/$1');
	$routes->get('change_pswd/(:segment)/(:segment)', 'Frame::change_pswd/$1/$2');
	$routes->post('change_pswd/(:segment)', 'Frame::change_pswd/$1');
});

$routes->group('workbench', static function ($routes) {
	$routes->get('page/(:segment)', 'Workbench::page/$1');
	$routes->post('query/(:segment)', 'Workbench::query/$1');
	$routes->post('queryPaged/(:segment)', 'Workbench::queryPaged/$1');
	$routes->post('drill/(:segment)', 'Workbench::drill/$1');
	$routes->post('debug/(:segment)', 'Workbench::debug/$1');
	$routes->get('import-columns/(:segment)', 'Workbench::importColumns/$1');
	$routes->post('import/(:segment)', 'Workbench::import/$1');
	$routes->get('add-fields/(:segment)', 'Workbench::addFields/$1');
	$routes->get('detail-fields/(:segment)', 'Workbench::detailFields/$1');
	$routes->get('batch-edit-fields/(:segment)', 'Workbench::batchEditFields/$1');
	$routes->post('add-row/(:segment)', 'Workbench::addRow/$1');
	$routes->get('popup-data/(:segment)', 'Workbench::popupData/$1');
	$routes->get('popup-levels/(:segment)', 'Workbench::popupLevels/$1');
	$routes->get('popup-level-data/(:segment)', 'Workbench::popupLevelData/$1');
	$routes->post('update-fields/(:segment)', 'Workbench::updateFields/$1');
	$routes->post('update-row/(:segment)', 'Workbench::updateRow/$1');
	$routes->post('batch-update-row/(:segment)', 'Workbench::batchUpdateRow/$1');
	$routes->post('delete-row/(:segment)', 'Workbench::deleteRow/$1');
	$routes->post('table-edit/(:segment)', 'Workbench::tableEdit/$1');
	$routes->post('upkeep/(:segment)', 'Workbench::upkeep/$1');
	$routes->get('chart/(:segment)', 'Workbench::chart/$1');
});

$routes->group('comment', static function ($routes) {
	$routes->get('fields/(:segment)', 'Comment::fields/$1');
	$routes->post('list/(:segment)', 'Comment::list/$1');
	$routes->post('add/(:segment)', 'Comment::add/$1');
});

$routes->group('dept', static function ($routes) {
	$routes->get('tree', 'DeptApi::tree');
	$routes->get('detail/(:segment)', 'DeptApi::detail/$1');
	$routes->post('add', 'DeptApi::add');
	$routes->post('update', 'DeptApi::update');
	$routes->post('delete', 'DeptApi::delete');
	$routes->get('options', 'DeptApi::options');
});

$routes->group('invitation', static function ($routes) {
	$routes->get('tree', 'InvitationApi::tree');
	$routes->get('detail/(:segment)', 'InvitationApi::detail/$1');
	$routes->post('add', 'InvitationApi::add');
	$routes->post('update', 'InvitationApi::update');
	$routes->post('delete', 'InvitationApi::delete');
	$routes->post('transfer', 'InvitationApi::transfer');
	$routes->get('options', 'InvitationApi::options');
});

$routes->group('interview', static function ($routes) {
	$routes->get('tree', 'InterviewApi::tree');
	$routes->get('detail/(:segment)', 'InterviewApi::detail/$1');
	$routes->post('add', 'InterviewApi::add');
	$routes->post('update', 'InterviewApi::update');
	$routes->post('delete', 'InterviewApi::delete');
	$routes->post('transfer', 'InterviewApi::transfer');
	$routes->get('options', 'InterviewApi::options');
});

$routes->group('train', static function ($routes) {
	$routes->get('tree', 'TrainApi::tree');
	$routes->get('detail/(:segment)', 'TrainApi::detail/$1');
	$routes->post('update', 'TrainApi::update');
	$routes->post('batchUpdate', 'TrainApi::batchUpdate');
	$routes->post('delete', 'TrainApi::delete');
	$routes->post('transfer', 'TrainApi::transfer');
	$routes->get('options', 'TrainApi::options');
});

$routes->group('employee', static function ($routes) {
	$routes->get('tree', 'EmployeeApi::tree');
	$routes->get('detail/(:segment)', 'EmployeeApi::detail/$1');
	$routes->post('update', 'EmployeeApi::update');
	$routes->post('batchUpdate', 'EmployeeApi::batchUpdate');
	$routes->post('delete', 'EmployeeApi::delete');
	$routes->get('options', 'EmployeeApi::options');
});

$routes->group('contract', static function ($routes) {
	$routes->get('list', 'ContractApi::list');
	$routes->get('detail/(:segment)', 'ContractApi::detail/$1');
	$routes->post('create', 'ContractApi::create');
	$routes->post('update', 'ContractApi::update');
	$routes->post('delete', 'ContractApi::delete');
	$routes->post('submit', 'ContractApi::submit');
	$routes->post('approve', 'ContractApi::approve');
	$routes->post('reject', 'ContractApi::reject');
	$routes->post('sign', 'ContractApi::sign');
	$routes->post('archive', 'ContractApi::archive');
	$routes->get('options', 'ContractApi::options');
	$routes->get('stats', 'ContractApi::stats');
	$routes->get('flow', 'ContractApi::flow');
	$routes->get('flow/(:segment)', 'ContractApi::flow/$1');
});
