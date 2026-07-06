<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use App\Controllers\BaseApiController;

class MatchApi extends BaseApiController
{

    public function page(string $functionCode = '')
    {
        try {
            $db = \Config\Database::connect('btdc');
            $funcRow = $db->table('def_function')
                ->where('有效标识', '1')
                ->where('功能编码', $functionCode)
                ->get()
                ->getRowArray();

            if (!$funcRow) {
                return $this->businessError("功能编码 {$functionCode} 不存在或已禁用");
            }

            $moduleNames = explode(',', $funcRow['模块名称'] ?? '');
            if (count($moduleNames) < 2) {
                return $this->businessError("功能 {$functionCode} 的模块名称配置不正确，需要两个模块名用英文逗号分隔");
            }

            $aModule = trim($moduleNames[0]);
            $bModule = trim($moduleNames[1]);

            $aConfig = $this->getModuleConfig($aModule);
            $bConfig = $this->getModuleConfig($bModule);

            $aColumns = $this->getModuleColumns($aModule);
            $bColumns = $this->getModuleColumns($bModule);

            $aMatchCols = $this->getMatchColumns($aModule);
            $bMatchCols = $this->getMatchColumns($bModule);

            $aData = $this->queryModuleData($aModule, $aConfig['queryTable'], $aConfig['queryWhere'], $aConfig['queryOrder'], $aMatchCols['target']);
            $bData = $this->queryModuleData($bModule, $bConfig['queryTable'], $bConfig['queryWhere'], $bConfig['queryOrder'], $bMatchCols['target']);

            return $this->success([
                'meta' => [
                    'functionCode' => $functionCode,
                    'title' => $funcRow['功能名称'] ?? '数据匹配',
                    'menu1' => $funcRow['菜单1'] ?? '',
                    'menu2' => $funcRow['菜单2'] ?? '',
                    'module' => $funcRow['模块名称'] ?? '',
                    'params' => $funcRow['参数'] ?? '',
                    'aModule' => $aModule,
                    'bModule' => $bModule,
                    'aConfig' => $aConfig,
                    'bConfig' => $bConfig,
                    'aColumns' => $aColumns,
                    'bColumns' => $bColumns,
                    'aMatchCols' => $aMatchCols,
                    'bMatchCols' => $bMatchCols,
                ],
                'aData' => $aData,
                'bData' => $bData,
            ], 'Success');

        } catch (\Exception $e) {
            return $this->businessError($e->getMessage());
        }
    }

    private function queryModuleData(string $moduleName, string $tableName, string $whereClause, string $orderClause, string $targetField): array
    {
        $db = \Config\Database::connect('btdc');

        $builder = $db->table($tableName);

        if ($whereClause) {
            $builder->where($whereClause);
        }

        if ($orderClause) {
            $builder->orderBy($orderClause);
        }

        $rows = $builder->get(10000, 0)->getResultArray();
        $total = count($rows);

        return [
            'rows' => $rows,
            'total' => $total,
        ];
    }

    private function getModuleConfig(string $moduleName): array
    {
        $db = \Config\Database::connect('btdc');
        $row = $db->table('def_query_config')
            ->where('查询模块', $moduleName)
            ->get()
            ->getRowArray();

        if (!$row) {
            throw new \RuntimeException("模块 {$moduleName} 未配置查询配置");
        }

        return [
            'queryModule' => $row['查询模块'] ?? '',
            'mode' => $row['模块类型'] ?? '数据查询',
            'fieldModule' => $row['字段模块'] ?? '',
            'queryTable' => $row['查询表名'] ?? '',
            'dataTable' => $row['数据表名'] ?? '',
            'dataModel' => $row['数据模式'] ?? '',
            'queryWhere' => $row['查询条件'] ?? '',
            'queryGroup' => $row['汇总条件'] ?? '',
            'queryOrder' => $row['排序条件'] ?? '',
            'resultCount' => $row['初始条数'] ?? 0,
            'commentModule' => $row['备注模块'] ?? '',
            'chartModule' => $row['图形模块'] ?? '',
            'tableStyle' => $row['表样式'] ?? '',
        ];
    }

    private function getModuleColumns(string $moduleName): array
    {
        $db = \Config\Database::connect('btdc');
        $rows = $db->table('def_query_column')
            ->where('查询模块', $moduleName)
            ->where('顺序 >', 0)
            ->orderBy('顺序')
            ->get()
            ->getResultArray();

        $columns = [];
        foreach ($rows as $row) {
            $columns[] = [
                'field' => $row['列名'] ?? $row['字段名'] ?? '',
                'title' => $row['查询名'] ?? $row['列名'] ?? $row['字段名'] ?? '',
                'type' => $row['列类型'] ?? '',
                'width' => intval($row['列宽度'] ?? 0),
                'hidden' => false,
                'editable' => false,
                'sortable' => true,
                'original' => $row
            ];
        }

        return $columns;
    }

    private function getMatchColumns(string $moduleName): array
    {
        $db = \Config\Database::connect('btdc');
        $rows = $db->table('def_query_column')
            ->where('查询模块', $moduleName)
            ->get()
            ->getResultArray();

        $result = ['key' => '', 'label' => '', 'amount' => '', 'target' => ''];

        foreach ($rows as $col) {
            $matchType = $col['可匹配'] ?? '';
            switch ($matchType) {
                case '1':
                    $result['key'] = $col['字段名'];
                    break;
                case '2':
                    $result['label'] = $col['字段名'];
                    break;
                case '3':
                    $result['amount'] = $col['字段名'];
                    break;
                case '4':
                    $result['target'] = $col['字段名'];
                    break;
            }
        }

        return $result;
    }

    private function getModuleTable(string $moduleName): string
    {
        $db = \Config\Database::connect('btdc');
        $row = $db->table('def_query_config')
            ->where('查询模块', $moduleName)
            ->get()
            ->getRowArray();

        if (!$row) {
            throw new \RuntimeException("模块 {$moduleName} 未找到对应的数据来源表");
        }

        return $row['数据来源表'] ?? '';
    }

    public function buildRelation()
    {
        try {
            $request = $this->request->getJSON(true);
            $aModule = $request['aModule'] ?? '';
            $bModule = $request['bModule'] ?? '';
            $aKeys = $request['aKeys'] ?? [];
            $bKeys = $request['bKeys'] ?? [];

            if (empty($aModule) || empty($bModule)) {
                return $this->businessError('请指定匹配模块');
            }
            if (empty($aKeys) || empty($bKeys)) {
                return $this->businessError('请选择要匹配的记录');
            }

            $aCols = $this->getMatchColumns($aModule);
            $bCols = $this->getMatchColumns($bModule);

            if (empty($aCols['key']) || empty($aCols['target'])) {
                return $this->businessError("模块 {$aModule} 未配置匹配字段（主键或标注字段）");
            }
            if (empty($bCols['key']) || empty($bCols['target'])) {
                return $this->businessError("模块 {$bModule} 未配置匹配字段（主键或标注字段）");
            }

            $aTable = $this->getModuleTable($aModule);
            $bTable = $this->getModuleTable($bModule);

            $db = \Config\Database::connect('btdc');
            $db->transStart();

            foreach ($aKeys as $aKey) {
                $existing = $db->table($aTable)
                    ->select($aCols['target'])
                    ->where($aCols['key'], $aKey)
                    ->get()
                    ->getRowArray();

                $currentTargets = $existing[$aCols['target']] ?? '';
                $targetArray = $currentTargets ? explode(',', $currentTargets) : [];
                $newTargets = array_unique(array_merge($targetArray, $bKeys));
                $newTargetStr = implode(',', $newTargets);

                $db->table($aTable)
                    ->set($aCols['target'], $newTargetStr)
                    ->where($aCols['key'], $aKey)
                    ->update();
            }

            foreach ($bKeys as $bKey) {
                $existing = $db->table($bTable)
                    ->select($bCols['target'])
                    ->where($bCols['key'], $bKey)
                    ->get()
                    ->getRowArray();

                $currentTargets = $existing[$bCols['target']] ?? '';
                $targetArray = $currentTargets ? explode(',', $currentTargets) : [];
                $newTargets = array_unique(array_merge($targetArray, $aKeys));
                $newTargetStr = implode(',', $newTargets);

                $db->table($bTable)
                    ->set($bCols['target'], $newTargetStr)
                    ->where($bCols['key'], $bKey)
                    ->update();
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->businessError('匹配关系建立失败');
            }

            MetadataCache::invalidateTable($aTable);
            MetadataCache::invalidateTable($bTable);

            return $this->success([
                'aKeys' => $aKeys,
                'bKeys' => $bKeys,
                'count' => count($aKeys) * count($bKeys)
            ], '匹配关系建立成功');

        } catch (\Exception $e) {
            return $this->businessError($e->getMessage());
        }
    }

    public function revokeRelation()
    {
        try {
            $request = $this->request->getJSON(true);
            $aModule = $request['aModule'] ?? '';
            $bModule = $request['bModule'] ?? '';
            $aKeys = $request['aKeys'] ?? [];
            $bKeys = $request['bKeys'] ?? [];
            $mode = $request['mode'] ?? 'specific';

            if (empty($aModule) || empty($bModule)) {
                return $this->businessError('请指定匹配模块');
            }
            if (empty($aKeys) && empty($bKeys)) {
                return $this->businessError('请选择要撤销的记录');
            }

            $aCols = $this->getMatchColumns($aModule);
            $bCols = $this->getMatchColumns($bModule);

            if (empty($aCols['key']) || empty($aCols['target'])) {
                return $this->businessError("模块 {$aModule} 未配置匹配字段");
            }
            if (empty($bCols['key']) || empty($bCols['target'])) {
                return $this->businessError("模块 {$bModule} 未配置匹配字段");
            }

            $aTable = $this->getModuleTable($aModule);
            $bTable = $this->getModuleTable($bModule);

            $db = \Config\Database::connect('btdc');
            $db->transStart();

            if ($mode === 'all') {
                if (!empty($aKeys)) {
                    $db->table($aTable)
                        ->set($aCols['target'], '')
                        ->whereIn($aCols['key'], $aKeys)
                        ->update();
                }
                if (!empty($bKeys)) {
                    $db->table($bTable)
                        ->set($bCols['target'], '')
                        ->whereIn($bCols['key'], $bKeys)
                        ->update();
                }
            } else {
                if (!empty($aKeys)) {
                    foreach ($aKeys as $aKey) {
                        $existing = $db->table($aTable)
                            ->select($aCols['target'])
                            ->where($aCols['key'], $aKey)
                            ->get()
                            ->getRowArray();

                        $currentTargets = $existing[$aCols['target']] ?? '';
                        $targetArray = $currentTargets ? explode(',', $currentTargets) : [];
                        $newTargets = array_diff($targetArray, $bKeys);
                        $newTargetStr = implode(',', $newTargets);

                        $db->table($aTable)
                            ->set($aCols['target'], $newTargetStr)
                            ->where($aCols['key'], $aKey)
                            ->update();
                    }
                }

                if (!empty($bKeys)) {
                    foreach ($bKeys as $bKey) {
                        $existing = $db->table($bTable)
                            ->select($bCols['target'])
                            ->where($bCols['key'], $bKey)
                            ->get()
                            ->getRowArray();

                        $currentTargets = $existing[$bCols['target']] ?? '';
                        $targetArray = $currentTargets ? explode(',', $currentTargets) : [];
                        $newTargets = array_diff($targetArray, $aKeys);
                        $newTargetStr = implode(',', $newTargets);

                        $db->table($bTable)
                            ->set($bCols['target'], $newTargetStr)
                            ->where($bCols['key'], $bKey)
                            ->update();
                    }
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->businessError('匹配关系撤销失败');
            }

            MetadataCache::invalidateTable($aTable);
            MetadataCache::invalidateTable($bTable);

            return $this->success([
                'aKeys' => $aKeys,
                'bKeys' => $bKeys,
                'mode' => $mode
            ], '匹配关系撤销成功');

        } catch (\Exception $e) {
            return $this->businessError($e->getMessage());
        }
    }
}
