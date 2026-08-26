<?php

namespace App\Services\Workbench;

use App\Exceptions\BusinessException;
use App\Models\Mcommon;
use App\Libraries\MetadataCache;
use App\Services\Workbench\ContextService;
use App\Services\Audit\AuditLogService;
use App\Services\Person\PersonService;

/**
 * 批量编辑服务类
 *
 * 负责工作台批量修改、表级编辑等批量操作，
 * 支持多种数据模式（直接update/CASE WHEN批量/软删+流水）。
 * 从 EditService 中拆分而来。
 */
class BatchEditService
{
    private Mcommon $model;

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 批量修改多条记录
     *
     * @param string $dataTable 数据表
     * @param string $dataModel 数据模式
     * @param string $primaryKey 主键字段
     * @param array $keyValues 主键值数组
     * @param array $formData 表单数据
     * @param string $userWorkid 用户工号
     * @param string $functionCode 功能编码
     * @return int 影响行数（失败的数据模式返回 -1）
     */
    public function batchUpdateRowsByModel(
        string $dataTable,
        string $dataModel,
        string $primaryKey,
        array $keyValues,
        array $formData,
        string $userWorkid,
        string $functionCode
    ): int {
        $updates = [];
        foreach ($formData as $key => $value) {
            if ($key !== $primaryKey) {
                $updates[] = sprintf('`%s` = %s', $key, $this->model->quote((string) $value));
            }
        }

        if (empty($updates)) {
            return 0;
        }

        // 主键值去重并转义，构建批量 IN 条件（本方法仅支持单字段主键，与原实现一致）
        $rawKeyValues = [];
        $quotedKeyValues = [];
        foreach ($keyValues as $keyVal) {
            $raw = (string) $keyVal;
            if (!in_array($raw, $rawKeyValues, true)) {
                $rawKeyValues[] = $raw;
                $quotedKeyValues[] = $this->model->quote($raw);
            }
        }
        $whereIn = sprintf('`%s` IN (%s)', $primaryKey, implode(',', $quotedKeyValues));

        switch ($dataModel) {
            case '0':
                // 人员审计表：UPDATE 前读旧行快照（含定位键/技术列，供 diff 定位）
                $audit = new AuditLogService();
                $oldRows = [];
                if ($audit->isAuditedTable($dataTable)) {
                    try {
                        $oldRows = $this->model->select(
                            sprintf('SELECT * FROM %s WHERE %s', $dataTable, $whereIn)
                        )->getResultArray() ?: [];
                    } catch (\Throwable $e) {
                        log_message('error', sprintf(
                            '[BatchEditService] 审计旧值快照读取失败(表=%s): %s',
                            $dataTable,
                            $e->getMessage()
                        ));
                    }
                }

                // 所有记录 SET 相同值，逐条 UPDATE 等价合并为一条批量 UPDATE（单条语句自带原子性）
                $sql = sprintf(
                    'UPDATE %s SET %s WHERE %s',
                    $dataTable,
                    implode(', ', $updates),
                    $whereIn
                );
                $this->model->sql_log('批量修改[0]', $functionCode, [
                    'table' => $dataTable,
                    'pk' => $primaryKey,
                    'pk_values' => $rawKeyValues,
                    'fields' => $formData,
                    'note' => '直接UPDATE(批量IN)',
                    'batch_count' => count($rawKeyValues),
                ]);
                $num = $this->model->exec($sql);

                // hr_audit_log 字段级 diff（严格模式：每行 × 相同表单新值）
                if ($audit->isAuditedTable($dataTable) && $num > 0 && !empty($oldRows)) {
                    $audit->logUpdateDiff($dataTable, $oldRows, $formData, $userWorkid, '工作台');
                }

                // 渐进式信息收集：阶段表编辑后同步身份字段到 hr_person
                $this->syncPersonFromStageEdit($dataTable, $oldRows, $formData, $userWorkid);

                $this->invalidateConfigCache($dataTable);
                return $num;

            case '1':
            case '2':
                $num = $this->batchUpdateFlowVersioned(
                    $dataTable,
                    $primaryKey,
                    $whereIn,
                    $rawKeyValues,
                    $formData,
                    $userWorkid,
                    $functionCode
                );
                $this->invalidateConfigCache($dataTable);
                return $num;

            default:
                return -1;
        }
    }

    /**
     * 流水模式批量修改（数据模式 1/2：旧记录批量置无效 + 批量插入新版本）
     *
     * 优化说明：原实现对每条记录循环执行 SELECT + UPDATE + INSERT（3N 次 SQL），
     * 现改为一次预取旧记录 + 一条批量置无效 + 一条多值 INSERT（固定 3 次 SQL），
     * 并用事务包裹：中途失败整体回滚，避免出现"旧记录已置无效而新版本未插入"
     * 导致记录在工作台查询中消失的数据完整性问题。
     *
     * @param string $dataTable 数据表
     * @param string $primaryKey 主键字段（单字段）
     * @param string $whereIn 主键 IN 条件（覆盖全部提交的主键值）
     * @param array $rawKeyValues 去重后的主键原始值
     * @param array $formData 表单数据（覆盖字段）
     * @param string $userWorkid 用户工号
     * @param string $functionCode 功能编码
     * @return int 新插入的记录数
     * @throws BusinessException 预取失败或事务提交失败时抛出
     */
    private function batchUpdateFlowVersioned(
        string $dataTable,
        string $primaryKey,
        string $whereIn,
        array $rawKeyValues,
        array $formData,
        string $userWorkid,
        string $functionCode
    ): int {
        $db = $this->model->getDb();
        $db->transStart();

        try {
            // 1. 一次预取全部旧记录，按主键值索引；FOR UPDATE 行锁防并发修改产生多条有效版本
            $sqlSelect = sprintf('SELECT * FROM %s WHERE %s FOR UPDATE', $dataTable, $whereIn);
            $result = $this->model->select($sqlSelect);
            if ($result === false) {
                throw new BusinessException(sprintf('批量修改失败:预取原始记录失败(表=%s)', $dataTable));
            }

            $originalRows = [];
            foreach ($result->getResultArray() as $row) {
                $originalRows[(string) $row[$primaryKey]] = $row;
            }

            // 只处理实际命中的主键，未命中的跳过（与原逐条实现行为一致）
            $hitKeyValues = [];
            foreach ($rawKeyValues as $raw) {
                if (isset($originalRows[$raw])) {
                    $hitKeyValues[] = $raw;
                }
            }
            if (empty($hitKeyValues)) {
                $db->transComplete();
                return 0;
            }

            $hitQuoted = array_map(fn($v) => $this->model->quote($v), $hitKeyValues);
            $hitWhereIn = sprintf('`%s` IN (%s)', $primaryKey, implode(',', $hitQuoted));

            // 2. 一条批量置无效（仅命中记录）
            $now = date('Y-m-d H:i:s');
            $sqlUpdateOld = sprintf(
                'UPDATE %s SET 操作记录="修改",操作来源="工作台",操作人员="%s",操作时间="%s",结束操作时间="%s",删除标识="1",有效标识="0" WHERE %s',
                $dataTable,
                $userWorkid,
                $now,
                $now,
                $hitWhereIn
            );
            $this->model->sql_log('批量修改[1-旧]', $functionCode, [
                'table' => $dataTable,
                'pk' => $primaryKey,
                'pk_values' => $hitKeyValues,
                'note' => '流水旧记录批量置无效',
                'batch_count' => count($hitKeyValues),
            ]);
            $this->model->exec($sqlUpdateOld);

            // 3. PHP 内存中合并旧值 + 表单新值，一条多值 INSERT 插入全部新版本
            $allFields = [];
            $insertValuesList = [];
            foreach ($hitKeyValues as $raw) {
                $originalRow = $originalRows[$raw];

                if (empty($allFields)) {
                    foreach ($originalRow as $key => $val) {
                        $allFields[] = sprintf('`%s`', $key);
                    }
                    $allFields = array_merge($allFields, [
                        '`操作记录`', '`操作来源`', '`操作人员`', '`操作时间`',
                        '`结束操作时间`', '`删除标识`', '`有效标识`',
                    ]);
                }

                $values = [];
                foreach ($originalRow as $key => $val) {
                    $values[] = array_key_exists($key, $formData)
                        ? $this->model->quote((string) $formData[$key])
                        : $this->model->quote((string) $val);
                }
                $values[] = '"新增"';
                $values[] = '"工作台"';
                $values[] = sprintf('"%s"', $userWorkid);
                $values[] = sprintf('"%s"', $now);
                $values[] = '""'; // 有效记录留空，置失效时才写操作时间
                $values[] = '"0"';
                $values[] = '"1"';

                $insertValuesList[] = '(' . implode(', ', $values) . ')';
            }

            $sqlInsert = sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $dataTable,
                implode(', ', $allFields),
                implode(', ', $insertValuesList)
            );
            $this->model->sql_log('批量修改[1-新]', $functionCode, [
                'table' => $dataTable,
                'pk' => $primaryKey,
                'pk_values' => $hitKeyValues,
                'fields' => $formData,
                'note' => '流水批量插新版本',
                'batch_count' => count($insertValuesList),
            ]);
            $num = $this->model->exec($sqlInsert);

            // hr_audit_log 字段级 diff（严格模式：同事务，失败随事务回滚）
            // 旧版本行快照（仅命中行）× 相同表单新值；记录GUID 定位旧版本行
            if ($num > 0 && (new AuditLogService())->isAuditedTable($dataTable)) {
                $hitOldRows = array_map(
                    fn($raw) => $originalRows[$raw],
                    $hitKeyValues
                );
                (new AuditLogService())->logUpdateDiff(
                    $dataTable,
                    $hitOldRows,
                    $formData,
                    $userWorkid,
                    '工作台'
                );

                // 渐进式信息收集：阶段表编辑后同步身份字段到 hr_person（同事务）
                $this->syncPersonFromStageEdit($dataTable, $hitOldRows, $formData, $userWorkid);
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        $db->transComplete();
        if ($db->transStatus() === false) {
            // 生产环境 DBDebug=false 时 SQL 失败不抛异常，此处兜底检测事务状态
            throw new BusinessException(sprintf(
                '批量修改失败:事务提交已回滚(表=%s,主键=%s,提交 %d 条)',
                $dataTable,
                $primaryKey,
                count($hitKeyValues)
            ));
        }

        return $num;
    }

    /**
     * 表级批量修改（按行提交，按字段分组；单条走 UPDATE，多条走 CASE WHEN 批量更新）
     *
     * @param string $dataTable 数据表
     * @param string $dataModel 数据模式 (0=直接 update/case-when；1/2=软删+插新流水)
     * @param string $primaryKey 主键字段
     * @param array $rows 待修改的多行数据
     * @param string $userWorkid 用户工号
     * @param string $functionCode 功能编码
     * @return array ['success' => bool, 'count' => int, 'message' => string]
     */
    public function tableEditByModel(
        string $dataTable,
        string $dataModel,
        string $primaryKey,
        array $rows,
        string $userWorkid,
        string $functionCode
    ): array {
        if (empty($rows)) {
            return ['success' => false, 'count' => 0, 'message' => '没有要提交的修改数据'];
        }

        $primaryKeyFields = array_map('trim', explode(';', $primaryKey));
        $missingKeys = [];
        foreach ($primaryKeyFields as $pk) {
            $has = false;
            foreach ($rows as $row) {
                if (array_key_exists($pk, $row) && $row[$pk] !== '' && $row[$pk] !== null) {
                    $has = true;
                    break;
                }
            }
            if (!$has) {
                $missingKeys[] = $pk;
            }
        }
        if (!empty($missingKeys)) {
            return [
                'success' => false,
                'count'   => 0,
                'message' => sprintf('表级修改失败:payload 中缺少主键字段 [%s],无法定位待修改记录', implode(', ', $missingKeys)),
            ];
        }

        $skipFields = ['操作记录', '操作来源', '操作人员', '操作时间', '结束操作时间', '删除标识'];

        // 人员审计表：统一预取旧行快照（按主键索引，供各分组 diff 定位）
        $audit = new AuditLogService();
        $isAuditedTable = $audit->isAuditedTable($dataTable);
        $oldRowMap = [];
        if ($isAuditedTable) {
            try {
                $pkVals = [];
                foreach ($rows as $row) {
                    $v = (string) ($row[$primaryKey] ?? '');
                    if ($v !== '') {
                        $pkVals[] = $this->model->quote($v);
                    }
                }
                if (!empty($pkVals)) {
                    $oldRows = $this->model->select(sprintf(
                        'SELECT * FROM %s WHERE `%s` IN (%s)',
                        $dataTable,
                        $primaryKey,
                        implode(',', array_unique($pkVals))
                    ))->getResultArray() ?: [];
                    foreach ($oldRows as $oldRow) {
                        $oldRowMap[(string) $oldRow[$primaryKey]] = $oldRow;
                    }
                }
            } catch (\Throwable $e) {
                log_message('error', sprintf(
                    '[BatchEditService] 审计旧值快照读取失败(表=%s): %s',
                    $dataTable,
                    $e->getMessage()
                ));
            }
        }

        $num = 0;
        switch ($dataModel) {
            case '0':
                $updateGroups = [];
                foreach ($rows as $row) {
                    $updateFields = [];
                    foreach ($row as $key => $value) {
                        if ($key !== $primaryKey && !in_array($key, $skipFields, true)) {
                            $updateFields[] = $key;
                        }
                    }
                    if (empty($updateFields)) {
                        continue;
                    }
                    sort($updateFields);
                    $groupKey = implode('|', $updateFields);

                    if (!isset($updateGroups[$groupKey])) {
                        $updateGroups[$groupKey] = [
                            'fields' => $updateFields,
                            'rows'   => [],
                        ];
                    }
                    $updateGroups[$groupKey]['rows'][] = $row;
                }

                foreach ($updateGroups as $group) {
                    $updateFields = $group['fields'];
                    $groupRows = $group['rows'];

                    if (count($groupRows) === 1) {
                        $row = $groupRows[0];
                        $where = $this->buildWhereFromPrimaryKey($row, $primaryKey);
                        if (empty($where)) {
                            continue;
                        }

                        $updates = [];
                        foreach ($row as $key => $value) {
                            if ($key !== $primaryKey && !in_array($key, $skipFields, true)) {
                                $updates[] = sprintf('`%s` = %s', $key, $this->model->quote((string) $value));
                            }
                        }

                        $sql = sprintf('UPDATE %s SET %s WHERE %s', $dataTable, implode(', ', $updates), $where);
                        $this->model->sql_log('表级修改[0]', $functionCode, [
                            'table' => $dataTable,
                            'pk' => $primaryKey,
                            'pk_values' => [$row[$primaryKey] ?? null],
                            'fields' => array_keys(array_filter(
                                $row,
                                fn($k) => $k !== $primaryKey && !in_array($k, $skipFields, true),
                                ARRAY_FILTER_USE_KEY
                            )),
                            'note' => '单条UPDATE',
                        ]);
                        $affectedRow = $this->model->exec($sql);

                        // hr_audit_log 字段级 diff（严格模式）
                        if ($isAuditedTable && $affectedRow > 0) {
                            $this->logTableEditRowDiff(
                                $audit,
                                $dataTable,
                                $oldRowMap,
                                $row,
                                $primaryKey,
                                $userWorkid
                            );
                        }

                        // 渐进式信息收集：阶段表编辑后同步身份字段到 hr_person
                        $this->syncPersonFromStageEditForRow($dataTable, $oldRowMap, $row, $primaryKey, $userWorkid);

                        $num += $affectedRow;
                    } else {
                        $caseStatements = [];
                        $primaryKeyValues = [];

                        foreach ($updateFields as $field) {
                            $caseParts = [];
                            foreach ($groupRows as $row) {
                                $pkValue = $this->model->quote((string) ($row[$primaryKey] ?? ''));
                                $fieldValue = $this->model->quote((string) ($row[$field] ?? ''));
                                $caseParts[] = sprintf('WHEN `%s` = %s THEN %s', $primaryKey, $pkValue, $fieldValue);
                                $primaryKeyValues[] = $pkValue;
                            }
                            $caseStatements[] = sprintf('`%s` = CASE %s ELSE `%s` END', $field, implode(' ', $caseParts), $field);
                        }

                        $primaryKeyValues = array_unique($primaryKeyValues);
                        $whereIn = sprintf('`%s` IN (%s)', $primaryKey, implode(',', $primaryKeyValues));

                        $sql = sprintf(
                            'UPDATE %s SET %s WHERE %s',
                            $dataTable,
                            implode(', ', $caseStatements),
                            $whereIn
                        );

                        $this->model->sql_log('表级修改[0]', $functionCode, [
                            'table' => $dataTable,
                            'pk' => $primaryKey,
                            'pk_values' => array_map(fn($r) => $r[$primaryKey] ?? null, $groupRows),
                            'fields' => $updateFields,
                            'note' => 'CASE WHEN批量UPDATE',
                            'batch_count' => count($groupRows),
                        ]);
                        $affectedGroup = $this->model->exec($sql);

                        // hr_audit_log 字段级 diff（严格模式：逐行 × 各自新值）
                        if ($isAuditedTable && $affectedGroup > 0) {
                            foreach ($groupRows as $groupRow) {
                                $this->logTableEditRowDiff(
                                    $audit,
                                    $dataTable,
                                    $oldRowMap,
                                    $groupRow,
                                    $primaryKey,
                                    $userWorkid
                                );
                            }
                        }

                        // 渐进式信息收集：阶段表编辑后同步身份字段到 hr_person
                        foreach ($groupRows as $groupRow) {
                            $this->syncPersonFromStageEditForRow($dataTable, $oldRowMap, $groupRow, $primaryKey, $userWorkid);
                        }

                        $num += $affectedGroup;
                    }
                }
                $this->invalidateConfigCache($dataTable);
                return ['success' => true, 'count' => $num, 'message' => sprintf('表级修改提交成功,修改了 %d 条记录', $num)];

            case '1':
            case '2':
                $primaryKeyValues = [];
                $validRows = [];
                foreach ($rows as $row) {
                    $where = $this->buildWhereFromPrimaryKey($row, $primaryKey);
                    if (empty($where)) {
                        continue;
                    }
                    $primaryKeyValues[] = $this->model->quote((string) ($row[$primaryKey] ?? ''));
                    $validRows[] = $row;
                }

                if (empty($validRows)) {
                    return ['success' => false, 'count' => 0, 'message' => '表级修改失败:payload 中缺少有效的主键值,无法定位待修改记录'];
                }

                $whereIn = sprintf('`%s` IN (%s)', $primaryKey, implode(',', $primaryKeyValues));
                $sqlSelect = sprintf('SELECT * FROM %s WHERE %s', $dataTable, $whereIn);
                $result = $this->model->select($sqlSelect);
                if ($result === false) {
                    return ['success' => false, 'count' => 0, 'message' => '批量查询原始记录失败'];
                }

                $originalRows = [];
                foreach ($result->getResultArray() as $row) {
                    $originalRows[$row[$primaryKey]] = $row;
                }

                $sqlUpdateOld = sprintf(
                    'UPDATE %s SET 操作记录="修改",操作来源="工作台",操作人员="%s",操作时间="%s",结束操作时间="%s",删除标识="1",有效标识="0" WHERE %s',
                    $dataTable,
                    $userWorkid,
                    date('Y-m-d H:i:s'),
                    date('Y-m-d H:i:s'),
                    $whereIn
                );
                $this->model->sql_log('表级修改[1-旧]', $functionCode, [
                    'table' => $dataTable,
                    'pk' => $primaryKey,
                    'pk_values' => array_map(fn($r) => $r[$primaryKey] ?? null, $validRows),
                    'note' => '流水旧记录批量置无效',
                    'batch_count' => count($validRows),
                ]);
                $this->model->exec($sqlUpdateOld);

                $insertValuesList = [];
                foreach ($validRows as $row) {
                    $pkValue = $row[$primaryKey];
                    if (!isset($originalRows[$pkValue])) {
                        continue;
                    }

                    $originalRow = $originalRows[$pkValue];
                    $fields = [];
                    $values = [];

                    foreach ($originalRow as $key => $val) {
                        if (isset($row[$key]) && !in_array($key, $skipFields, true)) {
                            $fields[] = sprintf('`%s`', $key);
                            $values[] = $this->model->quote((string) $row[$key]);
                        } elseif (!in_array($key, $skipFields, true)) {
                            $fields[] = sprintf('`%s`', $key);
                            $values[] = $this->model->quote((string) $val);
                        }
                    }

                    $fields[] = '`操作记录`';
                    $values[] = '"新增"';
                    $fields[] = '`操作来源`';
                    $values[] = '"工作台"';
                    $fields[] = '`操作人员`';
                    $values[] = sprintf('"%s"', $userWorkid);
                    $fields[] = '`操作时间`';
                    $values[] = sprintf('"%s"', date('Y-m-d H:i:s'));
                    $fields[] = '`结束操作时间`';
                    $values[] = '""'; // 有效记录留空，置失效时才写操作时间
                    $fields[] = '`删除标识`';
                    $values[] = '"0"';
                    $fields[] = '`有效标识`';
                    $values[] = '"1"';

                    $insertValuesList[] = '(' . implode(', ', $values) . ')';
                }

                if (!empty($insertValuesList)) {
                    $allFields = [];
                    if (!empty($validRows)) {
                        $firstPk = $validRows[0][$primaryKey];
                        if (isset($originalRows[$firstPk])) {
                            foreach ($originalRows[$firstPk] as $key => $val) {
                                if (!in_array($key, $skipFields, true)) {
                                    $allFields[] = sprintf('`%s`', $key);
                                }
                            }
                        }
                    }
                    $allFields = array_merge($allFields, ['`操作记录`', '`操作来源`', '`操作人员`', '`操作时间`', '`结束操作时间`', '`删除标识`', '`有效标识`']);

                    $sqlInsert = sprintf(
                        'INSERT INTO %s (%s) VALUES %s',
                        $dataTable,
                        implode(', ', $allFields),
                        implode(', ', $insertValuesList)
                    );
                    $this->model->sql_log('表级修改[1-新]', $functionCode, [
                        'table' => $dataTable,
                        'pk' => $primaryKey,
                        'pk_values' => array_map(fn($r) => $r[$primaryKey] ?? null, $validRows),
                        'note' => '流水批量插新',
                        'batch_count' => count($insertValuesList),
                    ]);
                    $num += $this->model->exec($sqlInsert);

                    // hr_audit_log 字段级 diff（严格模式）
                    // 表级流水模式无外层事务，失败抛异常由调用方感知（旧值 diff 语义仍正确）
                    if ($isAuditedTable && $num > 0) {
                        foreach ($validRows as $validRow) {
                            $this->logTableEditRowDiff(
                                $audit,
                                $dataTable,
                                $originalRows,
                                $validRow,
                                $primaryKey,
                                $userWorkid
                            );
                        }
                    }

                    // 渐进式信息收集：阶段表编辑后同步身份字段到 hr_person
                    foreach ($validRows as $validRow) {
                        $this->syncPersonFromStageEditForRow($dataTable, $originalRows, $validRow, $primaryKey, $userWorkid);
                    }
                }

                $this->invalidateConfigCache($dataTable);
                return ['success' => $num > 0, 'count' => $num, 'message' => $num > 0
                    ? sprintf('表级修改提交成功,修改了 %d 条记录', $num)
                    : sprintf('表级修改失败:未命中任何记录,请检查主键值是否正确(主键=%s,表=%s,共提交 %d 行)', $primaryKey, $dataTable, count($rows))];

            default:
                return ['success' => false, 'count' => 0, 'message' => sprintf('修改失败,数据模式[-%s-]错误', $dataModel)];
        }
    }

    /**
     * 表级编辑单行 diff 写入 hr_audit_log（严格模式）
     *
     * diff 字段与实际写入字段严格一致：排除主键与控制列（skipFields 同款清单）；
     * 旧行自预取映射按主键取，主键值缺失或旧行未命中时跳过（并发已变更场景）。
     */
    private function logTableEditRowDiff(
        AuditLogService $audit,
        string $dataTable,
        array $oldRowMap,
        array $row,
        string $primaryKey,
        string $userWorkid
    ): void {
        $pkVal = (string) ($row[$primaryKey] ?? '');
        if ($pkVal === '' || !isset($oldRowMap[$pkVal])) {
            return;
        }

        $skipFields = ['操作记录', '操作来源', '操作人员', '操作时间', '结束操作时间', '删除标识'];
        $diffData = [];
        foreach ($row as $key => $value) {
            if ($key === $primaryKey || in_array($key, $skipFields, true)) {
                continue;
            }
            $diffData[$key] = $value;
        }
        if (empty($diffData)) {
            return;
        }

        $audit->logUpdateDiff(
            $dataTable,
            [$oldRowMap[$pkVal]],
            $diffData,
            $userWorkid,
            '工作台'
        );
    }

    /**
     * 根据数据行与主键构建 WHERE 条件（分号分隔的复合主键）
     *
     * @param array $data
     * @param string $primaryKey
     * @return string
     */
    private function buildWhereFromPrimaryKey(array $data, string $primaryKey): string
    {
        $keys = explode(';', $primaryKey);
        $conditions = [];

        foreach ($keys as $key) {
            $key = trim($key);
            if (isset($data[$key])) {
                $conditions[] = sprintf('%s=%s', $key, $this->model->quote((string) $data[$key]));
            }
        }

        return implode(' and ', $conditions);
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
                '[BatchEditService] 配置表 %s 已修改，缓存自动失效',
                $tableName
            ));
        } catch (\Throwable $e) {
            log_message('error', sprintf(
                '[BatchEditService] 配置表 %s 缓存失效失败: %s',
                $tableName,
                $e->getMessage()
            ));
        }
    }

    /**
     * 阶段表编辑后同步身份字段到 hr_person 主档
     *
     * 仅对 ee_store/ee_interview/ee_train/ee_onjob 四张阶段表生效。
     * 从旧行快照取人员编码，按 PERSON_FIELDS 过滤表单数据，
     * 调用 PersonService::syncPersonFromEdit 回写主档（非空覆盖）。
     */
    private function syncPersonFromStageEdit(
        string $dataTable,
        array $oldRows,
        array $formData,
        string $userWorkid
    ): void {
        $stageTables = ['ee_store', 'ee_interview', 'ee_train', 'ee_onjob'];
        if (!in_array($dataTable, $stageTables, true)) {
            return;
        }

        $personService = new PersonService();
        $synced = [];
        foreach ($oldRows as $oldRow) {
            $personCode = trim((string) ($oldRow['人员编码'] ?? ''));
            if ($personCode === '' || isset($synced[$personCode])) {
                continue;
            }
            $synced[$personCode] = true;
            try {
                $personService->syncPersonFromEdit($personCode, $formData, $userWorkid);
            } catch (\Throwable $e) {
                log_message('error', sprintf(
                    '[BatchEditService] hr_person 同步失败(person=%s, table=%s): %s',
                    $personCode,
                    $dataTable,
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * 表级编辑逐行同步身份字段到 hr_person（按主键从 oldRowMap 取旧行）
     */
    private function syncPersonFromStageEditForRow(
        string $dataTable,
        array $oldRowMap,
        array $row,
        string $primaryKey,
        string $userWorkid
    ): void {
        $pkVal = (string) ($row[$primaryKey] ?? '');
        if ($pkVal === '' || !isset($oldRowMap[$pkVal])) {
            return;
        }
        $this->syncPersonFromStageEdit($dataTable, [$oldRowMap[$pkVal]], $row, $userWorkid);
    }
}
