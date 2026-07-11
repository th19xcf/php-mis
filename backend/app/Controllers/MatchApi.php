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

            // 通过 def_function.模块名称 反查每个表对应的功能编码，供前端显示
            $aFunctionCode = $this->resolveFunctionCodeByModule($aModule);
            $bFunctionCode = $this->resolveFunctionCodeByModule($bModule);

            $matchConditions = $this->getMatchConditions($functionCode);

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
                    'aFunctionCode' => $aFunctionCode,
                    'bFunctionCode' => $bFunctionCode,
                    'aConfig' => $aConfig,
                    'bConfig' => $bConfig,
                    'aColumns' => $aColumns,
                    'bColumns' => $bColumns,
                    'aMatchCols' => $aMatchCols,
                    'bMatchCols' => $bMatchCols,
                    'matchConditions' => $matchConditions,
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

    /**
     * 根据模块名称反查功能编码（def_function.模块名称 -> 功能编码）
     *
     * 用于让 match-data 的列/匹配字段配置与普通工作台走同一套 view_function 流程。
     * 取有效标识=1 且模块名称精确匹配的第一条（按功能编码升序）。
     *
     * @param string $moduleName 模块名称（如 公司_财务_收支明细）
     * @return string 功能编码（未找到返回空字符串）
     */
    private function resolveFunctionCodeByModule(string $moduleName): string
    {
        $db = \Config\Database::connect('btdc');
        $funcRow = $db->table('def_function')
            ->select('功能编码')
            ->where('有效标识', '1')
            ->where('模块名称', $moduleName)
            ->orderBy('功能编码')
            ->get()
            ->getRowArray();
        if ($funcRow && !empty($funcRow['功能编码'])) {
            return (string) $funcRow['功能编码'];
        }
        return '';
    }

    /**
     * 读取 def_match_config 表的匹配条件并解析
     *
     * 匹配条件字段格式：A.<A表字段>=B.<B表字段>，多条用英文分号分隔
     * 例如：A.贷方金额=B.财务计收金额;A.对方名称=B.对方名称
     *
     * 返回解析后的条件数组，每项含 aField、bField、text（原始文本）
     *
     * @param string $functionCode 功能编码
     * @return array 解析后的匹配条件数组，无配置时返回空数组
     */
    private function getMatchConditions(string $functionCode): array
    {
        $db = \Config\Database::connect('btdc');
        $row = $db->table('def_match_config')
            ->where('功能编码', $functionCode)
            ->get()
            ->getRowArray();

        if (!$row) {
            return [];
        }

        $rawConditions = trim((string) ($row['匹配条件'] ?? ''));
        if ($rawConditions === '') {
            return [];
        }

        $parts = array_map('trim', explode(';', $rawConditions));
        $result = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            // 解析 A.<aField>=B.<bField>
            if (preg_match('/^A\.(.+?)=B\.(.+)$/', $part, $m)) {
                $result[] = [
                    'aField' => trim($m[1]),
                    'bField' => trim($m[2]),
                    'text' => $part,
                ];
            }
        }

        return $result;
    }

    private function getModuleColumns(string $moduleName): array
    {
        // 与普通工作台（如功能编码 500001 -> 公司_财务_收支明细）走同一套列配置流程：
        // 通过 def_function.模块名称 反查功能编码，再从 view_function 取列定义。
        // 不回退 def_query_column（旧流程是错误的，会导致列配置来源不一致）。
        $functionCode = $this->resolveFunctionCodeByModule($moduleName);
        if ($functionCode === '') {
            return [];
        }

        // 复用 ContextService::loadColumns 的 SQL（view_function 视图，含 可匹配 字段）
        $sql = sprintf(
            'select 功能编码,字段模块,部门编码字段,部门全称字段,
                工号字段,属地字段,
                列名,列类型,列宽度,字段名,查询名,
                赋值类型,对象,对象名称,对象表名,缺省值,主键,
                工号限权,可筛选,可汇总,可新增,可修改,不可为空,可颜色标注,
                提示条件,提示样式设置,异常条件,异常样式设置,字符转换,
                加密显示,列顺序,可匹配
            from view_function
            where 功能编码=%s and 列顺序>0
            group by 列名
            order by 列顺序',
            $this->model->quote($functionCode)
        );
        $query = $this->model->select($sql);
        $rows = $query ? $query->getResultArray() : [];

        $columns = [];
        foreach ($rows as $row) {
            $title = (string) ($row['列名'] ?? '');
            $columns[] = [
                'field' => $title !== '' ? $title : (string) ($row['字段名'] ?? ''),
                'title' => (string) ($row['查询名'] ?? '') !== '' ? (string) $row['查询名'] : ($title !== '' ? $title : (string) ($row['字段名'] ?? '')),
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
        // 与 getModuleColumns 一致，统一走 view_function（已包含 可匹配 字段），
        // 不回退 def_query_column（旧流程是错误的）。
        $functionCode = $this->resolveFunctionCodeByModule($moduleName);
        if ($functionCode === '') {
            return ['key' => '', 'label' => '', 'amount' => '', 'target' => ''];
        }

        $sql = sprintf(
            'select 字段名,可匹配
            from view_function
            where 功能编码=%s and 列顺序>0
            group by 列名
            order by 列顺序',
            $this->model->quote($functionCode)
        );
        $query = $this->model->select($sql);
        $rows = $query ? $query->getResultArray() : [];

        $result = ['key' => '', 'label' => '', 'amount' => '', 'target' => ''];

        foreach ($rows as $col) {
            $matchType = (string) ($col['可匹配'] ?? '');
            $fieldName = (string) ($col['字段名'] ?? '');
            if ($fieldName === '') {
                continue;
            }
            switch ($matchType) {
                case '1':
                    $result['key'] = $fieldName;
                    break;
                case '2':
                    $result['label'] = $fieldName;
                    break;
                case '3':
                    $result['amount'] = $fieldName;
                    break;
                case '4':
                    $result['target'] = $fieldName;
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
