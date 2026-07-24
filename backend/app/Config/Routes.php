<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
// 根路径：代理到 SPA 入口（Home 控制器已下线）
$routes->get('/', static function () {
    $indexHtml = FCPATH . 'index.html';
    if (is_file($indexHtml)) {
        $body = file_get_contents($indexHtml);
        return service('response')
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setBody($body);
    }
    return service('response')
        ->setStatusCode(503)
        ->setBody('MIS frontend entry (index.html) not found. Please run "pnpm build" in frontend/.');
});

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

$routes->group('workbench', static function ($routes) {
	$routes->get('page/(:segment)', 'Workbench::page/$1');
	$routes->post('pageWithData/(:segment)', 'Workbench::pageWithData/$1');
	$routes->post('query/(:segment)', 'Workbench::query/$1');
	$routes->post('queryPaged/(:segment)', 'Workbench::queryPaged/$1');
	$routes->post('drill/(:segment)', 'Workbench::drill/$1');
	$routes->post('debug/(:segment)', 'Workbench::debug/$1');
	// 导入接口：迁出至 Workbench\WorkbenchImportController
	$routes->get('import-columns/(:segment)', 'Workbench\WorkbenchImportController::importColumns/$1');
	$routes->post('import/(:segment)', 'Workbench\WorkbenchImportController::import/$1');
	$routes->post('import-debug/(:segment)', 'Workbench\WorkbenchImportController::importDebug/$1');
	// 字段配置与记录增删改：迁出至 Workbench\WorkbenchEditController
	$routes->get('add-fields/(:segment)', 'Workbench\WorkbenchEditController::addFields/$1');
	$routes->get('detail-fields/(:segment)', 'Workbench\WorkbenchEditController::detailFields/$1');
	$routes->get('batch-edit-fields/(:segment)', 'Workbench\WorkbenchEditController::batchEditFields/$1');
	$routes->post('add-row/(:segment)', 'Workbench\WorkbenchEditController::addRow/$1');
	$routes->post('update-fields/(:segment)', 'Workbench\WorkbenchEditController::updateFields/$1');
	$routes->post('update-row/(:segment)', 'Workbench\WorkbenchEditController::updateRow/$1');
	$routes->post('batch-update-row/(:segment)', 'Workbench\WorkbenchEditController::batchUpdateRow/$1');
	$routes->post('delete-row/(:segment)', 'Workbench\WorkbenchEditController::deleteRow/$1');
	$routes->post('table-edit/(:segment)', 'Workbench\WorkbenchEditController::tableEdit/$1');
	$routes->post('upkeep/(:segment)', 'Workbench::upkeep/$1');
	// 图表接口：迁出至 Workbench\WorkbenchChartController
	$routes->get('chart/(:segment)', 'Workbench\WorkbenchChartController::chart/$1');
	$routes->post('chart-drill/(:segment)', 'Workbench\WorkbenchChartController::chartDrill/$1');
	// 弹窗接口：迁出至 Workbench\WorkbenchPopupController
	$routes->get('popup-data/(:segment)', 'Workbench\WorkbenchPopupController::popupData/$1');
	$routes->get('popup-levels/(:segment)', 'Workbench\WorkbenchPopupController::popupLevels/$1');
	$routes->get('popup-level-data/(:segment)', 'Workbench\WorkbenchPopupController::popupLevelData/$1');
	$routes->post('export/(:segment)', 'Workbench::export/$1');
	$routes->get('export-status/(:segment)', 'Workbench::exportStatus/$1');
});

$routes->group('comment', static function ($routes) {
	$routes->get('fields/(:segment)', 'Comment::fields/$1');
	$routes->post('list/(:segment)', 'Comment::list/$1');
	$routes->post('add/(:segment)', 'Comment::add/$1');
});

$routes->group('match', static function ($routes) {
	$routes->get('page/(:segment)', 'MatchApi::page/$1');
	$routes->post('buildRelation', 'MatchApi::buildRelation');
	$routes->post('revokeRelation', 'MatchApi::revokeRelation');
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

$routes->group('contractV2', static function ($routes) {
	$routes->get('list', 'ContractV2Api::list');
	$routes->get('detail', 'ContractV2Api::detail');
	$routes->post('detail', 'ContractV2Api::detail');
	$routes->post('create', 'ContractV2Api::create');
	$routes->post('update', 'ContractV2Api::update');
	$routes->post('delete', 'ContractV2Api::delete');
	$routes->post('submit', 'ContractV2Api::submit');
	$routes->post('approve', 'ContractV2Api::approve');
	$routes->get('stats', 'ContractV2Api::stats');
	$routes->post('stats', 'ContractV2Api::stats');
	$routes->get('options', 'ContractV2Api::options');
	$routes->get('pendingTasks', 'ContractV2Api::pendingTasks');
	$routes->post('pendingTasks', 'ContractV2Api::pendingTasks');
	$routes->get('doneTasks', 'ContractV2Api::doneTasks');
	$routes->post('doneTasks', 'ContractV2Api::doneTasks');
	$routes->get('myContracts', 'ContractV2Api::myContracts');
	$routes->post('myContracts', 'ContractV2Api::myContracts');
	$routes->get('flowDetail', 'ContractV2Api::flowDetail');
	$routes->post('flowDetail', 'ContractV2Api::flowDetail');
	$routes->post('uploadDocument', 'ContractV2Api::uploadDocument');
	$routes->post('deleteDocument', 'ContractV2Api::deleteDocument');
	$routes->get('downloadDocument/(:num)', 'ContractV2Api::downloadDocument/$1');
	$routes->get('downloadDocument', 'ContractV2Api::downloadDocument');
});

$routes->group('workflow', static function ($routes) {
	$routes->get('definition/list', 'WorkflowApi::definitionList');
	$routes->post('definition/list', 'WorkflowApi::definitionList');
	$routes->get('definition/detail', 'WorkflowApi::definitionDetail');
	$routes->post('definition/detail', 'WorkflowApi::definitionDetail');
	$routes->post('definition/create', 'WorkflowApi::definitionCreate');
	$routes->post('definition/update', 'WorkflowApi::definitionUpdate');
	$routes->post('definition/delete', 'WorkflowApi::definitionDelete');
	$routes->post('definition/activate', 'WorkflowApi::definitionActivate');
	$routes->post('definition/deactivate', 'WorkflowApi::definitionDeactivate');
	$routes->get('instance/list', 'WorkflowApi::instanceList');
	$routes->post('instance/list', 'WorkflowApi::instanceList');
	$routes->get('instance/detail', 'WorkflowApi::instanceDetail');
	$routes->post('instance/detail', 'WorkflowApi::instanceDetail');
	$routes->get('pendingTasks', 'WorkflowApi::pendingTasks');
	$routes->post('pendingTasks', 'WorkflowApi::pendingTasks');
	$routes->get('doneTasks', 'WorkflowApi::doneTasks');
	$routes->post('doneTasks', 'WorkflowApi::doneTasks');
	$routes->get('myInstances', 'WorkflowApi::myInstances');
	$routes->post('myInstances', 'WorkflowApi::myInstances');
	$routes->post('withdraw', 'WorkflowApi::withdraw');
});

$routes->group('onlyoffice', static function ($routes) {
	$routes->post('callback', 'OnlyOfficeCallback::index');
	$routes->get('callback', 'OnlyOfficeCallback::index');
	$routes->get('config', 'OnlyOfficeCallback::config');
	$routes->post('config', 'OnlyOfficeCallback::config');
	$routes->get('download', 'OnlyOfficeCallback::download');
});

$routes->group('cache', static function ($routes) {
	$routes->post('invalidate-table', 'CacheController::invalidateTable');
	$routes->post('invalidate-all', 'CacheController::invalidateAll');
	$routes->get('status', 'CacheController::status');
});

// 临时迁移 API（不纳入 JWT 过滤器，执行完后建议删除）
$routes->group('migration', static function ($routes) {
	$routes->get('status', 'MigrationApi::status');
	$routes->get('run', 'MigrationApi::run');
	$routes->post('run', 'MigrationApi::run');
});
