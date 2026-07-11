<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use App\Controllers\BaseApiController;
use App\Libraries\MetadataCache;

class MatchApi extends BaseApiController
{

    public function page(string $functionCode = '')
    {
        try {
            $metadataCache = new MetadataCache();
            $funcRow = $metadataCache->getFunctionConfig($functionCode);

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

            // 通过 def_function.模块名称 反查每个表对应的功能编码，供前端显示
            $aFunctionCode = $this->resolveFunctionCodeByModule($aModule);
            $bFunctionCode = $this->resolveFunctionCodeByModule($bModule);

            $matchConditions = $this->getMatchConditions($functionCode);
            $matchWrites = $this->getMatchWrites($functionCode);
            $matchKeyFields = $this->getMatchKeyFields($functionCode, $aModule, $bModule);

            // 构建 matchCols：key 从 def_match_config.A表主键/B表主键 读取
            // target 从写入指令的 targetField 推导（若无则回退到 view_function.可匹配=4）
            $aMatchCols = $this->getMatchColumns($aModule);
            $bMatchCols = $this->getMatchColumns($bModule);
            if (!empty($matchKeyFields['aKey'])) {
                $aMatchCols['key'] = $matchKeyFields['aKey'];
            }
            if (!empty($matchKeyFields['bKey'])) {
                $bMatchCols['key'] = $matchKeyFields['bKey'];
            }
            // target 优先用写入指令的第一个 targetField
            if (!empty($matchWrites['bToA'])) {
                $aMatchCols['target'] = $matchWrites['bToA'][0]['targetField'];
            }
            if (!empty($matchWrites['aToB'])) {
                $bMatchCols['target'] = $matchWrites['aToB'][0]['targetField'];
            }

            $aData = $this->queryModuleData($aModule, $aConfig['queryTable'], $aConfig['queryWhere'], $aConfig['queryOrder'], $aMatchCols['target']);
            $bData = $this->queryModuleData($bModule, $bConfig['queryTable'], $bConfig['queryWhere'], $bConfig['queryOrder'], $bMatchCols['target']);

            // 为每条记录计算 __matched 标记（基于写入指令）
            $matchTables = $this->getMatchTables($functionCode, $aModule, $bModule);
            $this->applyMatchedFlag($aData['rows'], $bData['rows'], $matchWrites, $matchTables['aTable'], $matchTables['bTable'], $matchKeyFields);

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
                    'matchWrites' => $matchWrites,
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

    /**
     * 为 A/B 记录计算 __matched 标记
     *
     * 匹配状态判断逻辑（基于写入指令）：
     * - A 侧：bToA 写入的 targetField（如 记账表ID=uuid）非空 → 已匹配
     * - B 侧：通过 aToB 写入的源/目标字段交叉引用，存在已匹配的 A 记录且字段值相等 → 已匹配
     * - 若查询表（视图）缺少写入指令涉及的字段，从物理表补充查询
     */
    private function applyMatchedFlag(array &$aRows, array &$bRows, array $matchWrites, string $aTable, string $bTable, array $matchKeyFields): void
    {
        $db = \Config\Database::connect('btdc');
        $bToAWrites = $matchWrites['bToA'] ?? [];
        $aToBWrites = $matchWrites['aToB'] ?? [];

        $aNeededFields = [];
        $bNeededFields = [];
        foreach ($bToAWrites as $w) {
            $aNeededFields[] = $w['targetField'];
        }
        foreach ($aToBWrites as $w) {
            if ($w['sourceType'] === 'field' && $w['sourceTable'] === 'A') {
                $aNeededFields[] = $w['sourceField'];
            }
            if ($w['sourceType'] === 'field' && $w['sourceTable'] === 'B') {
                $bNeededFields[] = $w['sourceField'];
            }
            $bNeededFields[] = $w['targetField'];
        }

        $aFirstRow = $aRows[0] ?? [];
        $aMissingFields = array_unique(array_filter($aNeededFields, fn($f) => !array_key_exists($f, $aFirstRow)));
        if (!empty($aMissingFields) && !empty($aRows)) {
            $this->supplementFieldsFromTable($db, $aRows, $aTable, $aMissingFields, $matchKeyFields['aKey'] ?? null);
        }

        $bFirstRow = $bRows[0] ?? [];
        $bMissingFields = array_unique(array_filter($bNeededFields, fn($f) => !array_key_exists($f, $bFirstRow)));
        if (!empty($bMissingFields) && !empty($bRows)) {
            $this->supplementFieldsFromTable($db, $bRows, $bTable, $bMissingFields, $matchKeyFields['bKey'] ?? null);
        }

        // A 侧标记：bToA 的 targetField 非空 → 已匹配
        $aMarkerField = null;
        if (!empty($bToAWrites)) {
            $aMarkerField = $bToAWrites[0]['targetField'];
        }

        if ($aMarkerField) {
            foreach ($aRows as &$row) {
                $row['__matched'] = !empty($row[$aMarkerField]);
            }
            unset($row);
        } else {
            foreach ($aRows as &$row) {
                $row['__matched'] = false;
            }
            unset($row);
        }

        // B 侧标记：通过 aToB 写入指令的源/目标字段交叉引用
        $aToBLink = null;
        foreach ($aToBWrites as $write) {
            if ($write['sourceType'] === 'field' && $write['sourceTable'] === 'A') {
                $aToBLink = $write;
                break;
            }
        }

        if ($aToBLink) {
            $aSourceField = $aToBLink['sourceField'];
            $bTargetField = $aToBLink['targetField'];

            $matchedAValues = [];
            foreach ($aRows as $aRow) {
                if (!empty($aRow['__matched'])) {
                    $val = (string) ($aRow[$aSourceField] ?? '');
                    if ($val !== '') {
                        $matchedAValues[$val] = true;
                    }
                }
            }

            foreach ($bRows as &$row) {
                $bTargetValue = (string) ($row[$bTargetField] ?? '');
                $row['__matched'] = $bTargetValue !== '' && isset($matchedAValues[$bTargetValue]);
            }
            unset($row);
        } else {
            foreach ($bRows as &$row) {
                $row['__matched'] = false;
            }
            unset($row);
        }
    }

    /**
     * 从物理表补充查询缺失的字段，按主键关联回填到 rows
     */
    private function supplementFieldsFromTable($db, array &$rows, string $table, array $missingFields, ?string $keyField): void
    {
        if (empty($rows) || empty($missingFields) || empty($table) || empty($keyField)) {
            return;
        }

        $firstRow = $rows[0];
        $pkField = null;
        if (array_key_exists($keyField, $firstRow)) {
            $pkField = $keyField;
        } else {
            $candidates = ['GUID', 'guid', 'ID', 'id'];
            foreach ($candidates as $ck) {
                if (array_key_exists($ck, $firstRow)) {
                    $pkField = $ck;
                    break;
                }
            }
        }
        if (!$pkField) {
            return;
        }

        $pkValues = [];
        foreach ($rows as $row) {
            $val = $row[$pkField] ?? null;
            if ($val !== null && $val !== '') {
                $pkValues[] = $val;
            }
        }
        if (empty($pkValues)) {
            return;
        }

        $selectFields = array_merge([$pkField], $missingFields);
        $supplementRows = $db->table($table)
            ->select($selectFields)
            ->whereIn($pkField, $pkValues)
            ->get()
            ->getResultArray();

        $supplementMap = [];
        foreach ($supplementRows as $srow) {
            $pkVal = (string) ($srow[$pkField] ?? '');
            if ($pkVal !== '') {
                $supplementMap[$pkVal] = $srow;
            }
        }

        foreach ($rows as &$row) {
            $pkVal = (string) ($row[$pkField] ?? '');
            if ($pkVal !== '' && isset($supplementMap[$pkVal])) {
                foreach ($missingFields as $f) {
                    if (array_key_exists($f, $supplementMap[$pkVal])) {
                        $row[$f] = $supplementMap[$pkVal][$f];
                    }
                }
            }
        }
        unset($row);
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
        $metadataCache = new MetadataCache();
        $funcRow = $metadataCache->getFunctionConfigByModule($moduleName);
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
        $row = $this->getMatchConfigRow($functionCode);
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

    /**
     * 读取 def_match_config 原始行（单次查询，供 getMatchConditions/getMatchWrites 复用）
     *
     * @param string $functionCode 功能编码
     * @return array|null def_match_config 行，无则 null
     */
    private function getMatchConfigRow(string $functionCode): ?array
    {
        $db = \Config\Database::connect('btdc');
        return $db->table('def_match_config')
            ->where('功能编码', $functionCode)
            ->get()
            ->getRowArray();
    }

    /**
     * 读取 def_match_config 的 A表名称/B表名称（数据表名）
     *
     * def_match_config.A表名称/B表名称 存储的是物理数据表名（非查询模块名），
     * 用于 buildRelation/revokeRelation 直接更新数据。
     * 若 def_match_config 未配置 A表名称/B表名称，回退到 def_query_config.数据来源表。
     *
     * @param string $functionCode 功能编码
     * @param string $aModule A 侧查询模块名（回退用）
     * @param string $bModule B 侧查询模块名（回退用）
     * @return array ['aTable' => string, 'bTable' => string]
     */
    private function getMatchTables(string $functionCode, string $aModule, string $bModule): array
    {
        $row = $this->getMatchConfigRow($functionCode);
        $cfgA = trim((string) ($row['A表名称'] ?? ''));
        $cfgB = trim((string) ($row['B表名称'] ?? ''));

        $aTable = $cfgA !== '' ? $cfgA : $this->getModuleTable($aModule);
        $bTable = $cfgB !== '' ? $cfgB : $this->getModuleTable($bModule);

        return ['aTable' => $aTable, 'bTable' => $bTable];
    }

    /**
     * 读取 def_match_config 表的字段写入配置
     *
     * A表写入B表 字段格式：B.<B表字段>=<source>，多条用分号分隔
     *   source 可以是 A.<A表字段>、B.<B表字段>、uuid 或字面量
     *   例如：B.银行流水号=A.银行唯一流水号
     *
     * B表写入A表 字段格式：A.<A表字段>=<source>，多条用分号分隔
     *   例如：A.记账表ID=uuid
     *
     * @param string $functionCode 功能编码
     * @return array ['aToB' => [...], 'bToA' => [...]]
     */
    private function getMatchWrites(string $functionCode): array
    {
        $row = $this->getMatchConfigRow($functionCode);
        if (!$row) {
            return ['aToB' => [], 'bToA' => []];
        }

        $aToBRaw = trim((string) ($row['A表写入B表'] ?? ''));
        $bToARaw = trim((string) ($row['B表写入A表'] ?? ''));

        return [
            'aToB' => $this->parseWriteInstructions($aToBRaw, 'B'),
            'bToA' => $this->parseWriteInstructions($bToARaw, 'A'),
        ];
    }

    /**
     * 解析写入指令字符串
     *
     * @param string $raw 原始字符串
     * @param string $targetPrefix 目标表前缀（A 或 B）
     * @return array 解析后的写入指令数组
     */
    private function parseWriteInstructions(string $raw, string $targetPrefix): array
    {
        if ($raw === '') {
            return [];
        }

        $parts = array_map('trim', explode(';', $raw));
        $result = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            // 格式：<targetPrefix>.<targetField>=<source>
            if (preg_match('/^' . preg_quote($targetPrefix, '/') . '\.(.+?)=(.+)$/', $part, $m)) {
                $targetField = trim($m[1]);
                $source = trim($m[2]);

                $sourceType = 'literal';
                $sourceTable = '';
                $sourceField = '';

                if (preg_match('/^A\.(.+)$/', $source, $sm)) {
                    $sourceType = 'field';
                    $sourceTable = 'A';
                    $sourceField = trim($sm[1]);
                } elseif (preg_match('/^B\.(.+)$/', $source, $sm)) {
                    $sourceType = 'field';
                    $sourceTable = 'B';
                    $sourceField = trim($sm[1]);
                } elseif (strtolower($source) === 'uuid') {
                    $sourceType = 'uuid';
                } else {
                    $sourceType = 'literal';
                    $sourceField = $source;
                }

                $result[] = [
                    'targetField' => $targetField,
                    'sourceType' => $sourceType,
                    'sourceTable' => $sourceTable,
                    'sourceField' => $sourceField,
                    'text' => $part,
                ];
            }
        }

        return $result;
    }

    /**
     * 生成 UUID v4
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * 根据写入指令解析源值
     *
     * @param array $write 写入指令
     * @param array $aRecord A 表记录
     * @param array $bRecord B 表记录
     * @return string 源值
     */
    private function resolveWriteSourceValue(array $write, array $aRecord, array $bRecord): string
    {
        if ($write['sourceType'] === 'uuid') {
            return $this->generateUuid();
        }
        if ($write['sourceType'] === 'field') {
            if ($write['sourceTable'] === 'A') {
                return (string) ($aRecord[$write['sourceField']] ?? '');
            }
            if ($write['sourceTable'] === 'B') {
                return (string) ($bRecord[$write['sourceField']] ?? '');
            }
        }
        // literal
        return $write['sourceField'];
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

        // 通过 MetadataCache 获取 view_function 列定义（含 可匹配 字段）
        $metadataCache = new MetadataCache();
        $rows = $metadataCache->getViewFunctionColumns($functionCode);

        $columns = [[
            'field' => '序号',
            'title' => '序号',
            'type' => '数值',
            'width' => 90,
            'hidden' => false,
            'editable' => false,
            'sortable' => true,
            'original' => []
        ]];
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

        $metadataCache = new MetadataCache();
        $rows = $metadataCache->getViewFunctionColumns($functionCode);

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

    /**
     * 读取 def_match_config 的 A表主键/B表主键（字段名）
     *
     * def_match_config.A表主键/B表主键 存储的是 A/B 表的主键字段名，
     * 用于 buildRelation/revokeRelation 定位记录。
     * 若 def_match_config 未配置，回退到 view_function.可匹配=1 的字段。
     *
     * @param string $functionCode 功能编码
     * @param string $aModule A 侧查询模块名（回退用）
     * @param string $bModule B 侧查询模块名（回退用）
     * @return array ['aKey' => string, 'bKey' => string]
     */
    private function getMatchKeyFields(string $functionCode, string $aModule, string $bModule): array
    {
        $row = $this->getMatchConfigRow($functionCode);
        $cfgAKey = trim((string) ($row['A表主键'] ?? ''));
        $cfgBKey = trim((string) ($row['B表主键'] ?? ''));

        $aKey = $cfgAKey !== '' ? $cfgAKey : ($this->getMatchColumns($aModule)['key'] ?? '');
        $bKey = $cfgBKey !== '' ? $cfgBKey : ($this->getMatchColumns($bModule)['key'] ?? '');

        return ['aKey' => $aKey, 'bKey' => $bKey];
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
            $functionCode = $request['functionCode'] ?? '';

            if (empty($aModule) || empty($bModule)) {
                return $this->businessError('请指定匹配模块');
            }
            if (empty($aKeys) || empty($bKeys)) {
                return $this->businessError('请选择要匹配的记录');
            }

            $aCols = ['key' => '', 'target' => ''];
            $bCols = ['key' => '', 'target' => ''];

            // 优先从 def_match_config 读取 key/table
            $matchKeyFields = $this->getMatchKeyFields($functionCode, $aModule, $bModule);
            $aCols['key'] = $matchKeyFields['aKey'];
            $bCols['key'] = $matchKeyFields['bKey'];

            // target 从写入指令的 targetField 推导，回退到 view_function.可匹配=4
            $writes = ['aToB' => [], 'bToA' => []];
            if (!empty($functionCode)) {
                $writes = $this->getMatchWrites($functionCode);
            }
            $aViewMatchCols = $this->getMatchColumns($aModule);
            $bViewMatchCols = $this->getMatchColumns($bModule);
            $aCols['target'] = !empty($writes['bToA']) ? $writes['bToA'][0]['targetField'] : $aViewMatchCols['target'];
            $bCols['target'] = !empty($writes['aToB']) ? $writes['aToB'][0]['targetField'] : $bViewMatchCols['target'];

            if (empty($aCols['key'])) {
                return $this->businessError("功能 {$functionCode} 未配置 A 表主键（def_match_config.A表主键）");
            }
            if (empty($bCols['key'])) {
                return $this->businessError("功能 {$functionCode} 未配置 B 表主键（def_match_config.B表主键）");
            }

            $aTable = $this->getMatchTables($functionCode, $aModule, $bModule)['aTable'];
            $bTable = $this->getMatchTables($functionCode, $aModule, $bModule)['bTable'];

            // 检查 target 字段是否已被写入指令覆盖
            $aTargetCovered = false;
            $bTargetCovered = false;
            foreach ($writes['bToA'] as $write) {
                if ($write['targetField'] === $aCols['target']) {
                    $aTargetCovered = true;
                }
            }
            foreach ($writes['aToB'] as $write) {
                if ($write['targetField'] === $bCols['target']) {
                    $bTargetCovered = true;
                }
            }

            $db = \Config\Database::connect('btdc');
            $db->transStart();

            // 预读 A、B 记录（用于解析字段源值）
            $aRecords = [];
            $bRecords = [];
            if (!empty($writes['aToB']) || !empty($writes['bToA'])) {
                foreach ($aKeys as $aKey) {
                    $aRecords[$aKey] = $db->table($aTable)
                        ->where($aCols['key'], $aKey)
                        ->get()
                        ->getRowArray() ?? [];
                }
                foreach ($bKeys as $bKey) {
                    $bRecords[$bKey] = $db->table($bTable)
                        ->where($bCols['key'], $bKey)
                        ->get()
                        ->getRowArray() ?? [];
                }
            }

            // 执行 aToB 写入：将 A 的字段值写入 B 记录
            foreach ($bKeys as $bKey) {
                $updateData = [];
                $bRecord = $bRecords[$bKey] ?? [];
                foreach ($writes['aToB'] as $write) {
                    if ($write['sourceType'] === 'field' && $write['sourceTable'] === 'A') {
                        // 收集所有 A 记录的源字段值，用英文分号拼接
                        $values = [];
                        foreach ($aKeys as $aKey) {
                            $aRecord = $aRecords[$aKey] ?? [];
                            $val = (string) ($aRecord[$write['sourceField']] ?? '');
                            if ($val !== '') {
                                $values[] = $val;
                            }
                        }
                        $value = implode(';', $values);
                    } else {
                        // uuid / literal / B 字段：单值
                        $firstAKey = $aKeys[0] ?? '';
                        $firstARecord = $aRecords[$firstAKey] ?? [];
                        $value = $this->resolveWriteSourceValue($write, $firstARecord, $bRecord);
                    }
                    $updateData[$write['targetField']] = $value;
                }
                if (!empty($updateData)) {
                    $db->table($bTable)
                        ->where($bCols['key'], $bKey)
                        ->update($updateData);
                    $err = $db->error();
                    if (!empty($err['code'])) {
                        throw new \RuntimeException("B表更新失败: [{$err['code']}] {$err['message']} (bKey={$bKey})");
                    }
                }
            }

            // 执行 bToA 写入：将 B 的字段值或 uuid 写入 A 记录
            foreach ($aKeys as $aKey) {
                $updateData = [];
                $aRecord = $aRecords[$aKey] ?? [];
                foreach ($writes['bToA'] as $write) {
                    if ($write['sourceType'] === 'field' && $write['sourceTable'] === 'B') {
                        $values = [];
                        foreach ($bKeys as $bKey) {
                            $bRecord = $bRecords[$bKey] ?? [];
                            $val = (string) ($bRecord[$write['sourceField']] ?? '');
                            if ($val !== '') {
                                $values[] = $val;
                            }
                        }
                        $value = implode(';', $values);
                    } elseif ($write['sourceType'] === 'uuid') {
                        $values = [];
                        foreach ($bKeys as $_) {
                            $values[] = $this->generateUuid();
                        }
                        $value = implode(';', $values);
                    } else {
                        $firstBKey = $bKeys[0] ?? '';
                        $firstBRecord = $bRecords[$firstBKey] ?? [];
                        $value = $this->resolveWriteSourceValue($write, $aRecord, $firstBRecord);
                    }
                    $updateData[$write['targetField']] = $value;
                }
                if (!empty($updateData)) {
                    $db->table($aTable)
                        ->where($aCols['key'], $aKey)
                        ->update($updateData);
                    $err = $db->error();
                    if (!empty($err['code'])) {
                        throw new \RuntimeException("A表更新失败: [{$err['code']}] {$err['message']} (aKey={$aKey})");
                    }
                }
            }

            // 回退：无写入指令时，或 target 字段未被覆盖时，执行旧的 key 写入逻辑
            if (empty($writes['bToA']) || !$aTargetCovered) {
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
            }

            if (empty($writes['aToB']) || !$bTargetCovered) {
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
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                $error = $db->error();
                $errorMsg = !empty($error['message']) ? $error['message'] : '匹配关系建立失败（事务状态异常）';
                throw new \RuntimeException($errorMsg);
            }

            $cache = new MetadataCache();
            $cache->invalidateTable($aTable);
            $cache->invalidateTable($bTable);

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
            $functionCode = $request['functionCode'] ?? '';

            if (empty($aModule) || empty($bModule)) {
                return $this->businessError('请指定匹配模块');
            }
            if (empty($aKeys) && empty($bKeys)) {
                return $this->businessError('请选择要撤销的记录');
            }

            $aCols = ['key' => '', 'target' => ''];
            $bCols = ['key' => '', 'target' => ''];

            // 优先从 def_match_config 读取 key
            $matchKeyFields = $this->getMatchKeyFields($functionCode, $aModule, $bModule);
            $aCols['key'] = $matchKeyFields['aKey'];
            $bCols['key'] = $matchKeyFields['bKey'];

            // target 从写入指令的 targetField 推导，回退到 view_function.可匹配=4
            $writes = ['aToB' => [], 'bToA' => []];
            if (!empty($functionCode)) {
                $writes = $this->getMatchWrites($functionCode);
            }
            $aViewMatchCols = $this->getMatchColumns($aModule);
            $bViewMatchCols = $this->getMatchColumns($bModule);
            $aCols['target'] = !empty($writes['bToA']) ? $writes['bToA'][0]['targetField'] : $aViewMatchCols['target'];
            $bCols['target'] = !empty($writes['aToB']) ? $writes['aToB'][0]['targetField'] : $bViewMatchCols['target'];

            if (empty($aCols['key'])) {
                return $this->businessError("功能 {$functionCode} 未配置 A 表主键（def_match_config.A表主键）");
            }
            if (empty($bCols['key'])) {
                return $this->businessError("功能 {$functionCode} 未配置 B 表主键（def_match_config.B表主键）");
            }

            $aTable = $this->getMatchTables($functionCode, $aModule, $bModule)['aTable'];
            $bTable = $this->getMatchTables($functionCode, $aModule, $bModule)['bTable'];

            // 收集需要清空的 B 表字段（aToB 的 targetField）
            $bClearFields = [];
            foreach ($writes['aToB'] as $write) {
                $bClearFields[$write['targetField']] = true;
            }
            // 收集需要清空的 A 表字段（bToA 的 targetField）
            $aClearFields = [];
            foreach ($writes['bToA'] as $write) {
                $aClearFields[$write['targetField']] = true;
            }

            $db = \Config\Database::connect('btdc');
            $db->transStart();

            if ($mode === 'all') {
                if (!empty($aKeys)) {
                    $updateData = [$aCols['target'] => ''];
                    foreach (array_keys($aClearFields) as $field) {
                        $updateData[$field] = '';
                    }
                    $db->table($aTable)
                        ->set($updateData)
                        ->whereIn($aCols['key'], $aKeys)
                        ->update();
                }
                if (!empty($bKeys)) {
                    $updateData = [$bCols['target'] => ''];
                    foreach (array_keys($bClearFields) as $field) {
                        $updateData[$field] = '';
                    }
                    $db->table($bTable)
                        ->set($updateData)
                        ->whereIn($bCols['key'], $bKeys)
                        ->update();
                }
            } else {
                // specific 模式：仅移除被撤销的 key，保留其他合法关系
                if (!empty($aKeys)) {
                    foreach ($aKeys as $aKey) {
                        $existing = $db->table($aTable)
                            ->select($aCols['target'])
                            ->where($aCols['key'], $aKey)
                            ->get()
                            ->getRowArray();

                        $currentTargets = $existing[$aCols['target']] ?? '';
                        // 兼容分号（写入指令）和逗号（旧版 key 回退）两种分隔符
                        $targetArray = $currentTargets ? preg_split('/[;,]/', $currentTargets) : [];
                        $targetArray = array_map('trim', $targetArray);
                        $newTargets = array_diff($targetArray, $bKeys);
                        $newTargetStr = implode(';', array_filter($newTargets, fn($v) => $v !== ''));

                        // 仅更新 target 字段，不清空其他写入字段，避免破坏其他合法关系
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
                        $targetArray = $currentTargets ? preg_split('/[;,]/', $currentTargets) : [];
                        $targetArray = array_map('trim', $targetArray);
                        $newTargets = array_diff($targetArray, $aKeys);
                        $newTargetStr = implode(';', array_filter($newTargets, fn($v) => $v !== ''));

                        $db->table($bTable)
                            ->set($bCols['target'], $newTargetStr)
                            ->where($bCols['key'], $bKey)
                            ->update();
                    }
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                $error = $db->error();
                $errorMsg = !empty($error['message']) ? $error['message'] : '匹配关系撤销失败';
                return $this->businessError($errorMsg);
            }

            $cache = new MetadataCache();
            $cache->invalidateTable($aTable);
            $cache->invalidateTable($bTable);

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
