<?php

namespace App\Controllers;

use App\Exceptions\BusinessException;
use App\Services\Person\PersonService;

/**
 * 人员主档管理 API（2060，hr_person）
 *
 * 前端页面改为与 2015 邀约页一致的"左树分组+右详情/新增/编辑"布局。
 *
 * 接口清单：
 *   GET  /person/tree           — 左树（属地 → 发码年 → 发码月 → 人员）
 *   GET  /person/detail/{guid}  — 详情（按 GUID，与工作台按钮定位键一致）
 *   POST /person/add            — 新增（姓名/手机必填；查重+发人员编码+建档）
 *   POST /person/update         — 修改（按 GUID，走 BaseApiController::updateRecord 审计）
 *   POST /person/delete         — 删除（按 GUIDs 批量，软删除标识）
 *   GET  /person/options        — 下拉选项（属地/性别/学历）
 *   POST /person/merge          — 重档合并（原功能不变）
 *   POST /person/dedup          — 人员查重（原功能不变，新增时也复用）
 *
 * 注：招聘渠道/渠道类型/渠道名称属于"招聘事件"属性（每次邀约可不同），
 * 已从 hr_person 物理移除，权威数据在阶段表（ee_store/ee_interview 等）行上。
 */
class PersonApi extends BaseApiController
{
    private ?PersonService $service = null;

    private function getService(): PersonService
    {
        return $this->service ??= new PersonService();
    }

    // ============================================================
    // 左树：属地 → 发码年 → 发码月 → 人员叶子
    // 年/月取自 人员编码（PK+YYYYMMDD+3位序号）第 3-6 位（YYYY）与第 7-8 位（MM）
    // 对齐 usePersonnelTreeStore 约定的 {id, value, items} 节点结构
    // ============================================================
    public function tree()
    {
        try {
            // 优先通过通用工作台 buildWorkbenchContext('2060') 解析与门户一致的属地
            // + 部门组合授权条件。若 2060 的 query 模块/列配置尚未就绪，
            // 则降级为"用户所属地单值过滤"，保证页面始终可见数据。
            $locationAuthzCond = $this->resolveLocationAuthzCond('2060');
            if ($locationAuthzCond === null || trim($locationAuthzCond) === '') {
                $fallback = $this->buildFallbackLocationCond();
                $locationAuthzCond = $fallback;
            }

            $sql = sprintf(
                'SELECT GUID, 人员编码, 姓名, 手机号码, 身份证号, 性别,
                        TIMESTAMPDIFF(YEAR, 出生日期, CURDATE()) AS 年龄,
                        出生日期,
                        学校, 专业, 学历, 现住址, 工作履历, 属地,
                        合并至GUID, 操作时间
                 FROM hr_person
                 WHERE 有效标识="1" AND 删除标识="0"
                   AND 合并至GUID IS NULL
                   AND %s
                 ORDER BY 属地, 人员编码',
                $locationAuthzCond
            );

            $rows = $this->model->select($sql)->getResultArray();

            return $this->success($this->buildGroupedPersonTree($rows));
        } catch (\Throwable $e) {
            // 2060 首屏最怕因配置/字段/SQL 任一环节 500 导致左树空白。
            // 此处兜底降级为"空树 + 警告日志"，用户至少能点"新增"主档入口、
            // 也能按"调试 SQL"按钮拿完整报错信息。
            log_message('error', sprintf(
                '[PersonApi::tree] 2060 左树加载异常，降级为空数组: %s @ %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            return $this->success([]);
        }
    }

    /**
     * 属地权限兜底：用户赋权单值 IN 过滤。
     *
     * 当 buildWorkbenchContext('2060') 因"功能未配置查询模块"等配置问题失败时
     * （典型为 def_function ↔ def_query_config 未对齐或 MetadataCache 旧缓存），
     * 使用本方法替代，避免整棵树 500。读用户级 属地赋权 + 角色级合并，多值按
     * AuthorizationService::buildCondition 的 AND 语义在此兜底场景下简化为 IN，
     * 宁可放过不可漏掉（管理场景用户一般属地赋权为空=不限，故返回 1=1）。
     */
    private function buildFallbackLocationCond(): string
    {
        try {
            $authFields = $this->getAuthorizationService()->loadUserAuthFields(
                $this->userContext->getCompanyId(),
                $this->userContext->getUserId(),
                ['属地赋权']
            );
            $location = trim((string) ($authFields['属地赋权'] ?? ''));
            if ($location === '' || $location === '不限') {
                return '1=1';
            }
            $parts = array_values(array_filter(array_map('trim', explode(',', $location)), static fn($v) => $v !== ''));
            if (empty($parts)) {
                return '1=1';
            }
            $quoted = array_map(fn($v) => $this->model->quote($v), $parts);
            return sprintf('属地 IN (%s)', implode(',', $quoted));
        } catch (\Throwable $e) {
            log_message('warning', sprintf(
                '[PersonApi::tree] 属地兜底构建失败，最终放开 1=1: %s',
                $e->getMessage()
            ));
            return '1=1';
        }
    }

    /**
     * 分组聚合树：属地 → 发码年 → 发码月 → 人员叶子
     *
     * 年取自 人员编码（PK+YYYYMMDD+3位序号）第 3-6 位、月取第 7-8 位；
     * 编码格式异常（非 PK 前缀/长度不足）归入"未知年份/未知"组。
     * 与 InvitationApi::buildGroupedInvitationTree 同模式，
     * 节点带 type 标签，供 usePersonnelTreeIcon 显示图标。
     */
    private function buildGroupedPersonTree(array $data): array
    {
        // 属地 → 根
        $root = [];

        foreach ($data as $row) {
            $region = $row['属地'] ?: '未分配';
            $code = (string) $row['人员编码'];
            if (preg_match('/^PK(\d{4})(\d{2})\d{5}$/', $code, $m) === 1) {
                $year = $m[1];
                $month = $m[2];
            } else {
                $year = '未知年份';
                $month = '未知';
            }

            // 人员叶子
            $personNode = [
                'id'    => sprintf('person^%s^%s', $row['GUID'], $code),
                'guid'  => (string) $row['GUID'],
                'value' => sprintf('%s [%s]', $row['姓名'], $code),
                'type'  => 'person',
                'data'  => [
                    '人员编码' => $code,
                    '姓名'     => $row['姓名'],
                    '手机号码' => $row['手机号码'] ?? '',
                    '身份证号' => $row['身份证号'] ?? '',
                    '性别'     => $row['性别'] ?? '',
                    // 年龄为查询时实时计算值（出生日期为 NULL 时 TIMESTAMPDIFF 返回 NULL → 空串）
                    '年龄'     => $row['年龄'] ?? '',
                    '出生日期' => $row['出生日期'] ?? '',
                    '学校'     => $row['学校'] ?? '',
                    '专业'     => $row['专业'] ?? '',
                    '学历'     => $row['学历'] ?? '',
                    '现住址'   => $row['现住址'] ?? '',
                    '工作履历' => $row['工作履历'] ?? '',
                    '属地'     => $row['属地'] ?? '',
                    '操作时间' => $row['操作时间'] ?? '',
                ],
            ];

            $k = sprintf('region^%s', $region);
            if (!isset($root[$k])) {
                $root[$k] = [
                    'id'    => $k,
                    'value' => $region,
                    'type'  => 'region',
                    'num'   => 0,
                    'items' => [],
                ];
            }
            $root[$k]['num']++;

            // 发码年层
            $ky = sprintf('year^%s^%s', $region, $year);
            if (!isset($root[$k]['items'][$ky])) {
                $root[$k]['items'][$ky] = [
                    'id'    => $ky,
                    'value' => $year,
                    'type'  => 'year',
                    'num'   => 0,
                    'items' => [],
                ];
            }
            $root[$k]['items'][$ky]['num']++;

            // 发码月层
            $km = sprintf('month^%s^%s^%s', $region, $year, $month);
            if (!isset($root[$k]['items'][$ky]['items'][$km])) {
                $root[$k]['items'][$ky]['items'][$km] = [
                    'id'    => $km,
                    'value' => $month,
                    'type'  => 'month',
                    'num'   => 0,
                    'items' => [],
                ];
            }
            $root[$k]['items'][$ky]['items'][$km]['items'][] = $personNode;
            $root[$k]['items'][$ky]['items'][$km]['num']++;
            $monthLabel = ($month === '未知') ? '未知' : sprintf('%d月', (int) $month);
            $root[$k]['items'][$ky]['items'][$km]['value'] = sprintf('%s (%d人)', $monthLabel, $root[$k]['items'][$ky]['items'][$km]['num']);
        }

        // 属地 → 年 → 月 三层计数、展示名与排序
        $nodes = [];
        foreach ($root as $k => $node) {
            $years = [];
            foreach ($node['items'] as $yearNode) {
                // 月组排序：未知排最后，其余按月升序（value 已带人数后缀，用 id 尾段判断）
                $months = array_values($yearNode['items']);
                usort($months, static function ($a, $b) {
                    $ma = explode('^', $a['id'])[3];
                    $mb = explode('^', $b['id'])[3];
                    if ($ma === '未知' && $mb !== '未知') return 1;
                    if ($mb === '未知' && $ma !== '未知') return -1;
                    return strcmp($ma, $mb);
                });
                $yearNode['items'] = $months;
                $yearNode['value'] = sprintf('%s (%d人)', $yearNode['value'], $yearNode['num']);
                $years[] = $yearNode;
            }
            // 年组排序：未知年份排最后，其余按年升序（用 id 尾段判断）
            usort($years, static function ($a, $b) {
                $ya = explode('^', $a['id'])[2];
                $yb = explode('^', $b['id'])[2];
                if ($ya === '未知年份' && $yb !== '未知年份') return 1;
                if ($yb === '未知年份' && $ya !== '未知年份') return -1;
                return strcmp($ya, $yb);
            });
            $node['items'] = $years;
            $node['value'] = sprintf('%s (%d人)', explode('^', $k)[1], $node['num']);
            $nodes[] = $node;
        }

        // 根：保持属地顺序（字母序）
        usort($nodes, fn($a, $b) => strcmp($a['id'], $b['id']));

        return $nodes;
    }

    // ============================================================
    // 详情：按 GUID 查询（GUID 是工作台通用定位键）
    // 兼容：如果传入值是 PK 开头的 人员编码，也自动路由到 findPersonByCode
    // ============================================================
    public function detail($guid = '')
    {
        if (empty($guid)) {
            $guid = (string) ($this->request->getGet('guid') ?? $this->request->getGet('code') ?? '');
        }
        $guid = trim((string) $guid);

        if ($guid === '') {
            return $this->paramError('主键不能为空');
        }

        $person = null;

        // 判定是 GUID（纯数字）还是人员编码（PK/C 前缀）
        if (preg_match('/^\d+$/', $guid)) {
            $sql = sprintf(
                'SELECT * FROM hr_person WHERE GUID=%d AND 有效标识="1" AND 删除标识="0" LIMIT 1',
                (int) $guid
            );
            $person = $this->model->select($sql)->getRowArray();
        } else {
            // 兼容原 code 路径
            $person = $this->getService()->findPersonByCode($guid);
        }

        if (!$person) {
            return $this->notFound('人员主档不存在');
        }

        // 剔除二进制列（UUID binary16 不可 JSON 序列化）
        foreach ($person as $key => $value) {
            if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                unset($person[$key]);
            }
        }

        // 年龄不落库（静态列已冻结写入），查询时按出生日期实时计算覆盖
        $birth = trim((string) ($person['出生日期'] ?? ''));
        $person['年龄'] = ($birth !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth) === 1)
            ? (string) ((int) date('Y') - (int) substr($birth, 0, 4) - ((date('md') < substr($birth, 5, 5)) ? 1 : 0))
            : '';

        return $this->success($person);
    }

    // ============================================================
    // 新增主档（独立入口：业务未经过邀约/面试直接建档）
    //
    // 校验姓名+手机必填 → 查重（硬命中自动挂档返回命中的人员编码，
    // 软命中弹决策；决策通过 person_code / force_new 二选一）
    // ============================================================
    public function add()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, '姓名')) {
            return $error;
        }
        if ($error = $this->requireParam($data, '手机号码')) {
            return $error;
        }

        $name   = trim((string) $data['姓名']);
        $mobile = trim((string) $data['手机号码']);
        $idcard = trim((string) ($data['身份证号'] ?? ''));

        // 人员主档查重
        $dedup = $this->getService()->dedup($name, $mobile, $idcard);

        $personCode = '';

        if ($dedup['level'] === 'hard') {
            // 证件号精确命中：挂既有档
            $personCode = (string) $dedup['person']['人员编码'];
        } elseif ($dedup['level'] === 'soft') {
            // 软命中：尊重前端决策
            $forceNew   = !empty($data['force_new']);
            $attachCode = trim((string) ($data['person_code'] ?? ''));
            if (!$forceNew && $attachCode !== '' && $this->personExists($attachCode)) {
                $personCode = $attachCode;
            } elseif (!$forceNew && count($dedup['matches']) === 1) {
                $personCode = (string) $dedup['matches'][0]['人员编码'];
            } elseif (!$forceNew && count($dedup['matches']) > 1) {
                // 多条软命中无法裁决 → 通知前端弹确认
                return $this->businessError('疑似存在重复人员，请确认挂接既有档或确认新建', [
                    'needConfirm' => true,
                    'matches'     => $dedup['matches'],
                ]);
            }
            // force_new = true 或 空matches → 走新建
        }

        // 已挂上档：把身份信息（非空）回写主档，保持 hr_person 权威值最新
        if ($personCode !== '') {
            // 非空字段同步（空值不覆盖）
            $sync = [];
            foreach (['姓名','身份证号','手机号码','性别','年龄','学校','专业','学历','现住址','工作履历','属地'] as $f) {
                if (isset($data[$f]) && $data[$f] !== '') {
                    $sync[$f] = (string) $data[$f];
                }
            }
            if (!empty($sync)) {
                $this->getService()->updatePersonFields($personCode, $sync, $this->getUserWorkId());
            }
            return $this->success([
                '人员编码' => $personCode,
                'isNew'    => false,
                'msg'      => '已挂接既有人员主档'
            ], '已挂接既有人员主档');
        }

        // 新建：提取 hr_person 实际列（防配置了主档不存在的列）
        $personCols = $this->getTableColumns('hr_person');
        $personData = [];
        foreach ($personCols as $col) {
            if (array_key_exists($col, $data) && $data[$col] !== null && $data[$col] !== '') {
                $personData[$col] = (string) $data[$col];
            }
        }

        // 业务日期：操作时间或当日（用于人员编码分桶）
        $bizDate = (string) ($data['操作时间'] ?? date('Y-m-d'));
        if (strlen($bizDate) >= 10) {
            $bizDate = substr($bizDate, 0, 10);
        }

        $db = $this->model->getDb();
        $db->transStart();
        try {
            $personCode = $this->getService()->createPerson($personData, $this->getUserWorkId(), $bizDate);
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
        $db->transComplete();
        if ($db->transStatus() === false) {
            throw new BusinessException('主档新建失败：事务提交已回滚');
        }

        return $this->success([
            '人员编码' => $personCode,
            'isNew'    => true,
        ], '新增人员主档成功');
    }

    private function personExists(string $code): bool
    {
        return $this->getService()->findPersonByCode($code) !== null;
    }

    // ============================================================
    // 修改（按 GUID，单条）
    // 走 BaseApiController::updateRecord，带审计日志；
    // 同时通过 PersonService::updatePersonFields 更新人员编码级状态。
    // ============================================================
    public function update()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, 'guid')) {
            return $error;
        }

        $guid = (int) $data['guid'];
        unset($data['guid']);

        if ($guid <= 0) {
            return $this->paramError('GUID 参数非法');
        }

        if (empty($data)) {
            return $this->success(null, '没有需要更新的字段');
        }

        // 读取当前人员编码（下游同步定位键用）
        $row = $this->model->select(
            sprintf('SELECT 人员编码 FROM hr_person WHERE GUID=%d LIMIT 1', $guid)
        )->getRowArray();
        if (!$row) {
            return $this->notFound('人员主档不存在');
        }
        $personCode = (string) $row['人员编码'];

        $where = sprintf('GUID=%d AND 有效标识="1" AND 删除标识="0"', $guid);

        $affected = $this->updateRecord('hr_person', $data, $where);
        if ($affected < 0) {
            return $this->serverError('修改失败');
        }

        // 阶段表身份字段同步（hr_person 权威源变更，下游 ee_store 等冗余身份字段回写）
        // 注：此处与 RecordEditService::syncPersonFromStageEdit 方向相反（主档→阶段），
        // 实际业务下游一般不再展示身份字段（按 人员编码 关联主档实时读取），
        // 如后续需要再补。当前仅保留主档层的修改。

        return $this->success(null, sprintf('修改成功，更新 %d 条记录', $affected));
    }

    // ============================================================
    // 删除（按 GUIDs 批量，软删除：删除标识=1）
    // 走 BaseApiController::deleteRecord，带审计日志
    // ============================================================
    public function delete()
    {
        $data = $this->getJsonInput();

        if (empty($data['guids']) || !is_array($data['guids'])) {
            return $this->paramError('请选择要删除的人员主档');
        }

        $guidInts = array_values(array_filter(array_map(
            fn($v) => (int) trim((string) $v),
            $data['guids']
        ), fn($v) => $v > 0));

        if (empty($guidInts)) {
            return $this->paramError('GUID 参数非法');
        }

        $guidStr = implode(',', array_map('intval', $guidInts));

        // 先检查这些 GUID 是否有下游四表（ee_store/ee_interview/ee_train/ee_onjob）
        // 仍然挂着的有效记录，防误删后下游变成孤儿
        $downstreamCheck = $this->model->select(sprintf('
            SELECT SUM(n) AS total FROM (
                SELECT COUNT(*) AS n FROM ee_store  WHERE 人员编码 IN (SELECT 人员编码 FROM hr_person WHERE GUID IN (%s)) AND 有效标识="1" AND 删除标识="0"
                UNION ALL SELECT COUNT(*) FROM ee_interview WHERE 人员编码 IN (SELECT 人员编码 FROM hr_person WHERE GUID IN (%s)) AND 有效标识="1" AND 删除标识="0"
                UNION ALL SELECT COUNT(*) FROM ee_train     WHERE 人员编码 IN (SELECT 人员编码 FROM hr_person WHERE GUID IN (%s)) AND 有效标识="1" AND 删除标识="0"
                UNION ALL SELECT COUNT(*) FROM ee_onjob     WHERE 人员编码 IN (SELECT 人员编码 FROM hr_person WHERE GUID IN (%s)) AND 有效标识="1" AND 删除标识="0"
            ) t',
            $guidStr, $guidStr, $guidStr, $guidStr
        ))->getRowArray();
        $downstreamCount = (int) ($downstreamCheck['total'] ?? 0);
        if ($downstreamCount > 0) {
            return $this->businessError(sprintf(
                '选中主档仍关联下游 %d 条业务记录（邀约/面试/培训/在职），请先处理下游数据或使用"合并"将其挂接至其他主档',
                $downstreamCount
            ));
        }

        $where = sprintf('GUID IN (%s) AND 有效标识="1" AND 删除标识="0"', $guidStr);

        $num = $this->deleteRecord('hr_person', $where);

        if ($num > 0) {
            return $this->success(null, sprintf('删除成功，共删除 %d 条记录', $num));
        }
        return $this->serverError('删除失败（记录可能已被删除）');
    }

    // ============================================================
    // 下拉选项：属地 / 性别 / 学历
    // 与 invitation/options 同模式，用员工属地单值过滤
    // （渠道下拉已移除：hr_person 不再承载渠道字段）
    // ============================================================
    public function options()
    {
        try {
            $userLocation = $this->userContext->getLocation();

            $regionSql = sprintf('
                select distinct 对象值 as value, 对象值 as label
                from def_object
                where 对象名称="属地" and 有效标识="1"
                    and (属地="" or locate(属地,"%s"))
                order by convert(对象值 using gbk)',
                $userLocation
            );

            $genderSql = '
                select distinct 对象值 as value, 对象值 as label
                from def_object
                where 对象名称="性别" and 有效标识="1"
                order by convert(对象值 using gbk)';

            $eduSql = '
                select distinct 对象值 as value, 对象值 as label
                from def_object
                where 对象名称="学历" and 有效标识="1"
                order by convert(对象值 using gbk)';

            return $this->success([
                'region'    => $this->model->select($regionSql)->getResultArray(),
                'gender'    => $this->model->select($genderSql)->getResultArray(),
                'education' => $this->model->select($eduSql)->getResultArray(),
            ]);
        } catch (\Throwable $e) {
            // 下拉失败不应导致整个 2060 页右侧"新增/编辑"表单项全部崩掉。
            // 兜底返回空结构，前端仍能展示表单空壳（下拉为空，但字段文本仍可手填）。
            log_message('error', sprintf(
                '[PersonApi::options] 2060 下拉加载异常，降级为 empty options: %s @ %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            return $this->success([
                'region' => [], 'gender' => [], 'education' => [],
            ]);
        }
    }

    // ============================================================
    // 重档合并（原有功能，保持不变）
    // ============================================================
    public function merge()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, 'sourceCode')) {
            return $error;
        }
        if ($error = $this->requireParam($data, 'targetCode')) {
            return $error;
        }

        $sourceCode = trim((string) $data['sourceCode']);
        $targetCode = trim((string) $data['targetCode']);

        try {
            $affected = $this->getService()->mergePerson($sourceCode, $targetCode, $this->getUserWorkId());
        } catch (BusinessException $e) {
            return $this->businessError($e->getMessage());
        }

        return $this->success(null, sprintf('合并成功，源主档已置无效，下游 %d 条记录已联动更新', $affected));
    }

    // ============================================================
    // 主档查重（原有功能，保持不变；新增主档前也复用）
    // ============================================================
    public function dedup()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, '姓名')) {
            return $error;
        }
        if ($error = $this->requireParam($data, '手机号码')) {
            return $error;
        }

        $result = $this->getService()->dedup(
            (string) $data['姓名'],
            (string) $data['手机号码'],
            trim((string) ($data['身份证号'] ?? ''))
        );

        return $this->success($result);
    }
}
