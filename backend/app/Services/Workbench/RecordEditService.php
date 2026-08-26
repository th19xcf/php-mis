<?php

namespace App\Services\Workbench;

use App\Models\Mcommon;
use App\Libraries\MetadataCache;
use App\Services\Workbench\ContextService;
use App\Services\Audit\AuditLogService;
use App\Exceptions\BusinessException;

/**
 * 单条记录编辑服务类
 *
 * 负责工作台单条记录的新增、修改、删除操作，
 * 支持多种数据模式（直接操作/软删+流水）。
 * 从 EditService 中拆分而来。
 */
class RecordEditService
{
    private Mcommon $model;

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 执行新增前处理
     *
     * @param string $beforeInsert 前处理模块
     */
    public function executeBeforeInsert(string $beforeInsert): void
    {
        if (!empty($beforeInsert)) {
            $spSql = sprintf('call %s("新增前", "")', $beforeInsert);
            $this->model->select($spSql);
        }
    }

    /**
     * 执行新增后处理
     *
     * @param string $afterInsert 后处理模块
     * @param string $primaryKey 主键字段
     * @param array $data 数据
     */
    public function executeAfterInsert(string $afterInsert, string $primaryKey, array $data): void
    {
        if (!empty($afterInsert) && !empty($primaryKey)) {
            $keyStr = $this->buildWhereFromData($data, $primaryKey);
            $spSql = sprintf('call %s("新增", "%s")', $afterInsert, $keyStr);
            $this->model->select($spSql);
        }
    }

    /**
     * 根据数据构建 WHERE 条件（逗号分隔的复合主键）
     *
     * @param array $data 数据
     * @param string $primaryKey 主键字段
     * @return string
     */
    public function buildWhereFromData(array $data, string $primaryKey): string
    {
        $keys = explode(',', $primaryKey);
        $conditions = [];

        foreach ($keys as $key) {
            $key = trim($key);
            if (isset($data[$key])) {
                $conditions[] = sprintf('%s=%s', $key, $this->model->quote((string) $data[$key]));
            }
        }

        return implode(' and ', $conditions);
    }

    /**
     * 模式0新增（基础模式）
     *
     * @param string $dataTable 数据表
     * @param array $data 数据
     * @return int 影响行数
     */
    public function addRowMode0(string $dataTable, array $data): int
    {
        $fields = [];
        $values = [];

        foreach ($data as $key => $value) {
            if ($key === '序号' || $key === 'SID') {
                continue;
            }

            $fields[] = $key;
            $values[] = is_array($value) ? json_encode($value) : $value;
        }

        if (empty($fields)) {
            return 0;
        }

        $fieldList = implode(',', $fields);
        $placeholders = implode(',', array_fill(0, count($values), '%s'));
        $sql = sprintf('insert into %s (%s) values (%s)', $dataTable, $fieldList, $placeholders);

        $this->model->query($sql, $values);
        $affected = $this->model->affectedRows();
        $this->logWorkbenchInsert($dataTable, $data, $affected);
        $this->invalidateConfigCache($dataTable);
        return $affected;
    }

    /**
     * 模式1新增（带创建人）
     *
     * @param string $dataTable 数据表
     * @param array $data 数据
     * @param string $userWorkid 用户工号
     * @return int 影响行数
     */
    public function addRowMode1(string $dataTable, array $data, string $userWorkid): int
    {
        $fields = ['创建人工号', '创建人姓名'];
        $values = [$userWorkid, ''];

        foreach ($data as $key => $value) {
            if ($key === '序号' || $key === 'SID') {
                continue;
            }

            $fields[] = $key;
            $values[] = is_array($value) ? json_encode($value) : $value;
        }

        $fieldList = implode(',', $fields);
        $placeholders = implode(',', array_fill(0, count($values), '%s'));
        $sql = sprintf('insert into %s (%s) values (%s)', $dataTable, $fieldList, $placeholders);

        $this->model->query($sql, $values);
        $affected = $this->model->affectedRows();
        $this->logWorkbenchInsert($dataTable, $data, $affected, $userWorkid);
        $this->invalidateConfigCache($dataTable);
        return $affected;
    }

    /**
     * 模式2新增（带创建人和创建时间）
     *
     * @param string $dataTable 数据表
     * @param array $data 数据
     * @param string $userWorkid 用户工号
     * @return int 影响行数
     */
    public function addRowMode2(string $dataTable, array $data, string $userWorkid): int
    {
        $fields = ['创建人工号', '创建人姓名', '创建时间'];
        $values = [$userWorkid, '', date('Y-m-d H:i:s')];

        foreach ($data as $key => $value) {
            if ($key === '序号' || $key === 'SID') {
                continue;
            }

            $fields[] = $key;
            $values[] = is_array($value) ? json_encode($value) : $value;
        }

        $fieldList = implode(',', $fields);
        $placeholders = implode(',', array_fill(0, count($values), '%s'));
        $sql = sprintf('insert into %s (%s) values (%s)', $dataTable, $fieldList, $placeholders);

        $this->model->query($sql, $values);
        $affected = $this->model->affectedRows();
        $this->logWorkbenchInsert($dataTable, $data, $affected, $userWorkid);
        $this->invalidateConfigCache($dataTable);
        return $affected;
    }

    /**
     * 工作台新增的 hr_audit_log 事件行（严格模式：失败抛异常，调用方感知）
     *
     * 记录GUID 取自增主键 insertID；UUID/定位键尝试读回新行，
     * 表无 UUID 列或读回失败时以 0x00×16 占位/空串兜底。
     */
    private function logWorkbenchInsert(
        string $dataTable,
        array $data,
        int $affected,
        string $userWorkid = ''
    ): void {
        $audit = new AuditLogService();
        if (!$audit->isAuditedTable($dataTable) || $affected <= 0) {
            return;
        }

        $newGuid = (int) $this->model->getDb()->insertID();

        // 动态检测定位列（对齐 BaseApiController::getTableColumns 模式）：
        // 六表中仅 hr_person/ee_application 有 UUID 列，ee_store 等无；
        // 仅 SELECT 实际存在的列，避免读回整行失败丢失全部定位信息
        $newRow = [];
        try {
            $colRows = $this->model->select("SHOW COLUMNS FROM `{$dataTable}`")->getResultArray();
            $columns = $colRows ? array_column($colRows, 'Field') : [];
            $locators = array_values(array_intersect(
                ['UUID', '人员编码', '候选人编码'],
                $columns
            ));
            if (!empty($locators)) {
                $colList = implode(',', array_merge(['GUID'], $locators));
                $newRow = $this->model->select(
                    sprintf('SELECT %s FROM `%s` WHERE GUID = %d', $colList, $dataTable, $newGuid)
                )->getRowArray() ?: [];
            }
        } catch (\Throwable $e) {
            log_message('error', sprintf(
                '[RecordEditService] 审计新增行读回失败(表=%s): %s',
                $dataTable,
                $e->getMessage()
            ));
        }

        $audit->logEvent([
            '人员编码'   => (string) ($newRow['人员编码'] ?? $data['人员编码'] ?? ''),
            '候选人编码' => (string) ($newRow['候选人编码'] ?? $data['候选人编码'] ?? ''),
            '表名'      => $dataTable,
            '记录GUID'  => $newGuid,
            '记录UUID'  => $newRow['UUID'] ?? null,
            '操作类型'  => '新增',
            '变更字段'  => '全部',
            '原值'      => null,
            '新值'      => '新增记录',
            '操作人员'  => $userWorkid,
            '操作来源'  => '工作台',
        ]);
    }

    /**
     * 根据数据模式修改记录
     *
     * @param string $dataTable 数据表
     * @param string $dataModel 数据模式 (0=直接update; 1/2=软删+插新流水)
     * @param string $primaryKey 主键字段
     * @param array $keyValues 主键值数组
     * @param array $formData 表单数据
     * @param string $userWorkid 用户工号
     * @param string $functionCode 功能编码
     * @return int 影响行数
     */
    public function updateRowByModel(
        string $dataTable,
        string $dataModel,
        string $primaryKey,
        array $keyValues,
        array $formData,
        string $userWorkid,
        string $functionCode
    ): int {
        $keyStr = implode(',', array_map(fn($v) => $this->model->quote((string) $v), $keyValues));
        $where = sprintf('%s in (%s)', $primaryKey, $keyStr);

        $updates = [];
        foreach ($formData as $key => $value) {
            if ($key !== $primaryKey) {
                $updates[] = sprintf('`%s` = %s', $key, $this->model->quote((string) $value));
            }
        }

        if (empty($updates)) {
            return 0;
        }

        switch ($dataModel) {
            case '0':
                // 人员审计表：UPDATE 前读旧行快照（含定位键/技术列，供 diff 定位）
                $audit = new AuditLogService();
                $oldRows = [];
                if ($audit->isAuditedTable($dataTable)) {
                    try {
                        $oldRows = $this->model->select(
                            sprintf('SELECT * FROM %s WHERE %s', $dataTable, $where)
                        )->getResultArray() ?: [];
                    } catch (\Throwable $e) {
                        log_message('error', sprintf(
                            '[RecordEditService] 审计旧值快照读取失败(表=%s): %s',
                            $dataTable,
                            $e->getMessage()
                        ));
                    }
                }

                $sql = sprintf(
                    'UPDATE %s SET %s WHERE %s',
                    $dataTable,
                    implode(', ', $updates),
                    $where
                );
                $this->model->sql_log('修改[0]', $functionCode, [
                    'table' => $dataTable,
                    'pk' => $primaryKey,
                    'pk_values' => $keyValues,
                    'fields' => $formData,
                    'note' => '直接UPDATE',
                ]);
                $affected = $this->model->exec($sql);

                // hr_audit_log 字段级 diff（严格模式：失败抛异常，调用方感知）
                if ($audit->isAuditedTable($dataTable) && $affected > 0 && !empty($oldRows)) {
                    $audit->logUpdateDiff($dataTable, $oldRows, $formData, $userWorkid, '工作台');
                }

                $this->invalidateConfigCache($dataTable);
                return $affected;

            case '1':
            case '2':
                // 事务保护：置无效与插新版本必须同成败，防止 UPDATE 成功、INSERT 失败导致记录"消失"
                // （对齐 BatchEditService::batchUpdateFlowVersioned 的事务写法）
                $db = $this->model->getDb();
                $db->transStart();

                try {
                    // FOR UPDATE 行锁：锁住原行至事务提交，防止并发修改同一记录产生两条"有效"版本
                    $sqlSelect = sprintf('SELECT * FROM %s WHERE %s FOR UPDATE', $dataTable, $where);
                    $result = $this->model->select($sqlSelect);
                    if ($result === false) {
                        throw new BusinessException(sprintf('修改失败:查询原始记录失败(表=%s)', $dataTable));
                    }
                    $originalRow = $result->getRowArray();
                    if (empty($originalRow)) {
                        $db->transComplete();
                        return 0;
                    }

                    $now = date('Y-m-d H:i:s');
                    $sqlUpdateOld = sprintf(
                        'UPDATE %s SET 操作记录="修改",操作来源="工作台",操作人员="%s",操作时间="%s",结束操作时间="%s",删除标识="1",有效标识="0" WHERE %s',
                        $dataTable,
                        $userWorkid,
                        $now,
                        $now,
                        $where
                    );
                    $this->model->sql_log('修改[1-旧]', $functionCode, [
                        'table' => $dataTable,
                        'pk' => $primaryKey,
                        'pk_values' => $keyValues,
                        'note' => '流水旧记录置无效',
                    ]);
                    $this->model->exec($sqlUpdateOld);

                    // 用关联数组构建，保证每个列只出现一次：原行打底 -> formData 覆盖业务字段
                    // -> 系统审计字段强制覆盖。避免原行/表单已含审计列时与追加的审计列重复，
                    // 触发 MySQL "Column 'xxx' specified twice" 错误。
                    $row = [];
                    foreach ($originalRow as $key => $val) {
                        // 跳过主键（GUID 为表自增列），由数据库自增生成新值，
                        // 不沿用原行主键导致新版本主键冲突
                        if ($key === $primaryKey) {
                            continue;
                        }
                        $row[$key] = array_key_exists($key, $formData)
                            ? (string) $formData[$key]
                            : (string) $val;
                    }
                    $row['操作记录'] = '新增';
                    $row['操作来源'] = '工作台';
                    $row['操作人员'] = $userWorkid;
                    $row['操作时间'] = $now;
                    $row['结束操作时间'] = ''; // 有效记录留空，与历史数据一致；置失效时才写操作时间
                    $row['删除标识'] = '0';
                    $row['有效标识'] = '1';

                    $fields = array_map(fn($k) => sprintf('`%s`', $k), array_keys($row));
                    $values = array_map(fn($v) => $this->model->quote((string) $v), array_values($row));

                    $sqlInsert = sprintf(
                        'INSERT INTO %s (%s) VALUES (%s)',
                        $dataTable,
                        implode(', ', $fields),
                        implode(', ', $values)
                    );
                    $this->model->sql_log('修改[1-新]', $functionCode, [
                        'table' => $dataTable,
                        'pk' => $primaryKey,
                        'pk_values' => $keyValues,
                        'fields' => $formData,
                        'note' => '流水插新版本',
                    ]);
                    $affected = $this->model->exec($sqlInsert);

                    // hr_audit_log 字段级 diff（严格模式：同事务，失败随事务回滚）
                    // 旧版本行快照 × 表单新值；记录GUID 定位旧版本行（变更发生地）
                    if ((new AuditLogService())->isAuditedTable($dataTable)) {
                        (new AuditLogService())->logUpdateDiff(
                            $dataTable,
                            [$originalRow],
                            $formData,
                            $userWorkid,
                            '工作台'
                        );
                    }
                } catch (\Throwable $e) {
                    $db->transRollback();
                    throw $e;
                }

                $db->transComplete();
                if ($db->transStatus() === false) {
                    // 生产环境 DBDebug=false 时 SQL 失败不抛异常，此处兜底检测事务状态
                    throw new BusinessException(sprintf(
                        '修改失败:事务提交已回滚(表=%s,主键=%s)',
                        $dataTable,
                        $primaryKey
                    ));
                }

                // 事务提交成功后才失效配置缓存，回滚时数据未变无需失效
                $this->invalidateConfigCache($dataTable);
                return $affected;

            default:
                return -1;
        }
    }

    /**
     * 根据数据模式删除记录
     *
     * @param string $dataTable 数据表
     * @param string $dataModel 数据模式
     * @param string $primaryKey 主键字段
     * @param array $keyValues 主键值数组
     * @param string $userWorkid 用户工号
     * @param string $functionCode 功能编码
     * @return int 影响行数（失败的数据模式返回 -1）
     */
    public function deleteRowByModel(
        string $dataTable,
        string $dataModel,
        string $primaryKey,
        array $keyValues,
        string $userWorkid,
        string $functionCode
    ): int {
        $keyStr = implode(',', array_map(fn($v) => $this->model->quote((string) $v), $keyValues));
        $where = sprintf('%s in (%s)', $primaryKey, $keyStr);

        // 人员审计表：删除前读行快照（定位键/技术列，供日志定位）
        $audit = new AuditLogService();
        $isAuditedTable = $audit->isAuditedTable($dataTable);
        $oldRows = [];
        if ($isAuditedTable) {
            try {
                $oldRows = $this->model->select(
                    sprintf('SELECT * FROM %s WHERE %s', $dataTable, $where)
                )->getResultArray() ?: [];
            } catch (\Throwable $e) {
                log_message('error', sprintf(
                    '[RecordEditService] 审计删除快照读取失败(表=%s): %s',
                    $dataTable,
                    $e->getMessage()
                ));
            }
        }

        switch ($dataModel) {
            case '0':
                $sql = sprintf('DELETE FROM %s WHERE %s', $dataTable, $where);
                $this->model->sql_log('删除[0]', $functionCode, [
                    'table' => $dataTable,
                    'pk' => $primaryKey,
                    'pk_values' => $keyValues,
                    'note' => '硬删除',
                ]);
                $affected = $this->model->exec($sql);

                // hr_audit_log 删除事件（严格模式：失败抛异常，调用方感知）
                if ($isAuditedTable && $affected > 0) {
                    $this->logDeleteEvents($audit, $dataTable, $oldRows, $userWorkid);
                }

                $this->invalidateConfigCache($dataTable);
                return $affected;

            case '1':
            case '2':
                $sql = sprintf(
                    'UPDATE %s SET 操作记录="删除",操作来源="工作台",操作人员="%s",操作时间="%s",结束操作时间="%s",删除标识="1",有效标识="0" WHERE %s',
                    $dataTable,
                    $userWorkid,
                    date('Y-m-d H:i:s'),
                    date('Y-m-d H:i:s'),
                    $where
                );
                $this->model->sql_log('删除[1]', $functionCode, [
                    'table' => $dataTable,
                    'pk' => $primaryKey,
                    'pk_values' => $keyValues,
                    'note' => '流水软删除',
                ]);
                $affected = $this->model->exec($sql);

                // hr_audit_log 删除事件（严格模式：失败抛异常，调用方感知）
                if ($isAuditedTable && $affected > 0) {
                    $this->logDeleteEvents($audit, $dataTable, $oldRows, $userWorkid);
                }

                $this->invalidateConfigCache($dataTable);
                return $affected;

            default:
                return -1;
        }
    }

    /**
     * 逐行写删除事件到 hr_audit_log（记录GUID/UUID/定位键自快照取值）
     */
    private function logDeleteEvents(
        AuditLogService $audit,
        string $dataTable,
        array $oldRows,
        string $userWorkid
    ): void {
        if (empty($oldRows)) {
            // 快照缺失（读取失败/并发已删）：仍留痕一行，定位键为空
            $audit->logEvent([
                '表名'      => $dataTable,
                '记录GUID'  => 0,
                '操作类型'  => '删除',
                '变更字段'  => '全部',
                '原值'      => '删除前记录',
                '新值'      => null,
                '操作人员'  => $userWorkid,
                '操作来源'  => '工作台',
            ]);
            return;
        }

        foreach ($oldRows as $oldRow) {
            $audit->logEvent([
                '人员编码'   => (string) ($oldRow['人员编码'] ?? ''),
                '候选人编码' => (string) ($oldRow['候选人编码'] ?? ''),
                '表名'      => $dataTable,
                '记录GUID'  => (int) ($oldRow['GUID'] ?? 0),
                '记录UUID'  => $oldRow['UUID'] ?? null,
                '操作类型'  => '删除',
                '变更字段'  => '全部',
                '原值'      => '删除前记录',
                '新值'      => null,
                '操作人员'  => $userWorkid,
                '操作来源'  => '工作台',
            ]);
        }
    }

    private function invalidateConfigCache(string $dataTable): void
    {
        static $configTables = [
            'def_query_column', 'def_query_config', 'def_function', 'def_user',
            'def_chart_config', 'def_chart_chart_column', 'def_chart_drill_config',
            'def_role_group', 'def_role', 'def_function_group',
            'def_drill_config', 'def_import_config', 'def_import_column',
            'def_comment_config', 'def_object', 'def_match_config',
            'def_config_table'
        ];
        $tableName = strtolower(trim($dataTable));
        if (!in_array($tableName, $configTables, true)) {
            return;
        }

        try {
            $metadataCache = new MetadataCache();
            $metadataCache->invalidateTable($tableName);

            $contextService = new ContextService();
            $contextService->clearCache();

            log_message('info', sprintf(
                '[RecordEditService] 配置表 %s 已修改，缓存自动失效',
                $tableName
            ));
        } catch (\Throwable $e) {
            log_message('error', sprintf(
                '[RecordEditService] 配置表 %s 缓存失效失败: %s',
                $tableName,
                $e->getMessage()
            ));
        }
    }
}
