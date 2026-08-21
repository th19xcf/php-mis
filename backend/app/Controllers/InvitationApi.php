<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Exceptions\AuthException;
use App\Exceptions\BusinessException;
use App\Exceptions\ValidationException;
use App\Services\Person\PersonService;

class InvitationApi extends BaseApiController
{
    public function tree()
    {
        // 属地权限：与 2010 同源（走 ContextService，含部门授权优先、upkeepAuth）
        $locationAuthzCond = $this->resolveLocationAuthzCond('2015');
        if ($locationAuthzCond === null) {
            return $this->serverError('无法获取属地权限');
        }

        $sql = sprintf('
            select
                GUID,姓名,身份证号,性别,年龄,手机号码,
                学校,专业,现住址,属地,
                邀约结果,招聘渠道,邀约日期,邀约人,
                邀约业务,邀约岗位,预约面试日期,
                if(面试信息="","待面试",面试信息) as 面试信息
            from ee_store
            where %s and 有效标识="1" and 删除标识="0"
            order by 属地,field(邀约结果,"通过","未通过","考虑","拒绝","未邀约"),面试信息,招聘渠道,convert(姓名 using gbk)',
            $locationAuthzCond);

        $results = $this->model->select($sql)->getResultArray();
        $tree = $this->buildGroupedInvitationTree($results);

        return $this->success($tree);
    }

    /**
     * 调试：打印左侧邀约树加载的完整 SQL + 分段耗时
     *
     * 权限：与 pageMeta.toolbar.debugSql 同一判定（前端按钮 v-if="canDebug" 同源）
     *   debugAuth = 代理登录 (JWT debugEnabled) OR def_user.调试赋权=1
     *   与 ContextService::loadUserAuthorization 中 debugAuth 的判定完全一致
     */
    public function debugTree()
    {
        // 调试权限校验：与 pageMeta.toolbar.debugSql 同源，避免按钮可见但接口拒绝
        if (! $this->hasDebugSqlAuth()) {
            return $this->serverError('无调试权限');
        }

        $totalStart = hrtime(true);

        // 1. 构建工作台上下文（与 tree() 同源，与 2010 完全一致）
        //    直接调 buildWorkbenchContext 一次性拿到完整诊断信息：
        //    用户级属地赋权 / 部门条件 / 最终属地条件
        $contextStart = hrtime(true);
        try {
            [$context] = $this->getContextService()->buildWorkbenchContext('2015');
        } catch (AuthException | BusinessException | ValidationException $e) {
            log_message('error', '[InvitationApi::debugTree] 构建上下文失败: ' . $e->getMessage());
            return $this->serverError('无法获取属地权限: ' . $e->getMessage());
        }
        $locationAuthzCond = (string) ($context['locationAuthzCond'] ?? '');
        if ($locationAuthzCond === '') {
            $locationAuthzCond = '1=1';
        }
        $userLocationAuth   = (string) ($context['user']['locationAuth'] ?? '');
        $deptAuthzCond      = (string) ($context['deptAuthzCond'] ?? '');
        $contextEnd = hrtime(true);

        // 2. 构建 SQL（与 tree() 完全一致）
        $sql = sprintf('
            select
                GUID,姓名,身份证号,性别,年龄,手机号码,
                学校,专业,现住址,属地,
                邀约结果,招聘渠道,邀约日期,邀约人,
                邀约业务,邀约岗位,预约面试日期,
                if(面试信息="","待面试",面试信息) as 面试信息
            from ee_store
            where %s and 有效标识="1" and 删除标识="0"
            order by 属地,field(邀约结果,"通过","未通过","考虑","拒绝","未邀约"),面试信息,招聘渠道,convert(姓名 using gbk)',
            $locationAuthzCond);

        // 3. 执行查询
        $queryStart = hrtime(true);
        $results = $this->model->select($sql)->getResultArray();
        $queryEnd = hrtime(true);

        // 4. 构建树
        $buildStart = hrtime(true);
        $tree = $this->buildGroupedInvitationTree($results);
        $buildEnd = hrtime(true);

        $totalEnd = hrtime(true);

        return $this->success([
            'sql'                    => $sql,
            'locationAuthzCondition' => $locationAuthzCond,
            'userLocationAuth'       => $userLocationAuth,
            'deptAuthzCondition'     => $deptAuthzCond,
            'rowCount'               => count($results),
            'treeNodeCount'          => count($tree),
            'timing' => [
                'contextBuildMs' => round(($contextEnd - $contextStart) / 1e6, 2),
                'queryMs'        => round(($queryEnd - $queryStart) / 1e6, 2),
                'buildTreeMs'    => round(($buildEnd - $buildStart) / 1e6, 2),
                'totalMs'        => round(($totalEnd - $totalStart) / 1e6, 2),
            ],
        ]);
    }

    /**
     * 调试 SQL 权限判定、ContextService 单例、属地权限解析
     * 已上提到 BaseApiController（hasDebugSqlAuth / getContextService / resolveLocationAuthzCond）
     * 4 个子类（InvitationApi/InterviewApi/TrainApi/EmployeeApi）共用同一份实现。
     */

    public function detail($guid = '')
    {
        if (empty($guid)) {
            $guid = $this->getGuidFromRequest();
        }

        if (empty($guid)) {
            return $this->paramError('人员GUID不能为空');
        }

        $selectFields = $this->buildDetailSelectFields('2015', 'ee_store');

        $sql = sprintf('
            select %s
            from ee_store
            where GUID="%s" and 有效标识="1" and 删除标识="0"',
            $selectFields,
            $guid);

        $result = $this->model->select($sql)->getRowArray();

        if (!$result) {
            return $this->notFound('人员不存在');
        }

        return $this->success($result);
    }

    /**
     * 新增邀约（人员主档建档入口）
     *
     * 字段归属路由（def_query_column.字段归属表）：
     * - 身份字段（姓名/证件号/手机等）→ hr_person 主档
     * - 实例字段（邀约业务/岗位/渠道等）→ ee_store
     * - 兼容双写：身份字段同时写 ee_store 同名列，过渡期存量单表查询不受影响
     *
     * 人员归属裁决：
     * - person_code 非空 → 前端查重确认后显式挂既有档
     * - 证件号精确命中（hard）→ 直接挂既有档（同一人再投递是正常业务）
     * - 手机+姓名疑似（soft）且未 force_new → 返回 needConfirm，前端弹窗后带参重提
     * - 无命中 / force_new → 事务内新建主档（sp_生成人员编码 发号）
     *
     * 事务：建档（或挂档同步）与 ee_store 写入同事务，失败整体回滚。
     */
    public function add()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, '姓名')) {
            return $error;
        }
        if ($error = $this->requireParam($data, '手机号码')) {
            return $error;
        }

        // 前端查重确认后的显式决策参数（非业务字段，不参与入库）
        $attachCode = trim((string) ($data['person_code'] ?? ''));
        $forceNew = !empty($data['force_new']);
        unset($data['person_code'], $data['force_new']);

        // 字段归属路由：身份字段 → hr_person，实例字段 → ee_store（+兼容双写）
        $groups = $this->splitDataByFieldOwner('2015', 'ee_store', $data);
        $personData = $groups['hr_person'] ?? [];
        $storeData = $groups['ee_store'] ?? [];

        // 过滤为主档实际列（防配置了归属但主档无该列）
        $personCols = $this->getTableColumns('hr_person');
        $personData = array_intersect_key($personData, array_flip($personCols));

        $personService = new PersonService();
        $personCode = '';

        if ($attachCode !== '') {
            // 显式挂档：校验主档存在
            if ($personService->findPersonByCode($attachCode) === null) {
                return $this->businessError('指定的人员主档不存在，请刷新后重试');
            }
            $personCode = $attachCode;
        } else {
            $dedup = $personService->dedup(
                (string) $data['姓名'],
                (string) $data['手机号码'],
                trim((string) ($data['身份证号'] ?? ''))
            );
            if ($dedup['level'] === 'hard') {
                // 证件号唯一命中：直接挂既有档，同时以表单最新身份信息回写主档
                $personCode = (string) $dedup['person']['人员编码'];
            } elseif ($dedup['level'] === 'soft' && !$forceNew) {
                // 疑似重复：交前端确认（挂既有 person_code / 确认新建 force_new）
                return $this->error(ApiCode::BUSINESS_ERROR, '存在疑似重复的人员主档，请确认是否为同一人', [
                    'needConfirm' => true,
                    'matches' => $dedup['matches'],
                ]);
            }
            // none / forceNew：保持 $personCode=''，事务内新建
        }

        $db = $this->model->getDb();
        $db->transStart();

        try {
            if ($personCode === '') {
                $personCode = $personService->createPerson(
                    $personData,
                    $this->getUserWorkId(),
                    (string) ($storeData['邀约日期'] ?? '')
                );
            } elseif (!empty($personData)) {
                // 挂既有档：表单身份信息较新（如换了手机号），回写主档（空值跳过）
                $personService->updatePersonFields($personCode, $personData, $this->getUserWorkId());
            }

            $storeData['人员编码'] = $personCode;
            $storeData = $this->buildInsertData($storeData);
            // 生成候选人编码：按邀约日期分桶发号，LAST_INSERT_ID 防多人并发重号
            $storeData['候选人编码'] = $this->generateCandidateCode(1, $storeData['邀约日期'] ?? '');
            $num = $this->insertRecord('ee_store', $storeData);

            if ($num <= 0) {
                throw new BusinessException('新增邀约信息失败');
            }
        } catch (BusinessException $e) {
            $db->transRollback();
            return $this->businessError($e->getMessage());
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[InvitationApi::add] 事务回滚: ' . $e->getMessage());
            return $this->serverError('新增邀约信息失败');
        }

        $db->transComplete();
        if ($db->transStatus() === false) {
            // 生产环境 DBDebug=false 时 SQL 失败不抛异常，兜底检测事务状态
            return $this->serverError('新增邀约信息失败(事务已回滚)');
        }

        return $this->success(['人员编码' => $personCode], '新增邀约信息成功');
    }

    /**
     * 人员主档查重（邀约新增弹窗 / 导入确认页共用）
     *
     * 入参：姓名、手机号码（必填）、身份证号（可选）
     * 返回：{ level: hard|soft|none, matches: [...疑似列表], person: hard 命中行 }
     */
    public function dedup()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, '姓名')) {
            return $error;
        }
        if ($error = $this->requireParam($data, '手机号码')) {
            return $error;
        }

        $result = (new PersonService())->dedup(
            (string) $data['姓名'],
            (string) $data['手机号码'],
            trim((string) ($data['身份证号'] ?? ''))
        );

        return $this->success($result);
    }

    /**
     * 生成候选人编码
     *
     * 格式：C + YYYYMMDD + 3位顺序号，例如 C20260818001
     * 日期来源：ee_store.邀约日期（业务日期，非录入日期）
     *   - 历史数据录入/导入不及时时，编码日期与业务日期一致，时序正确
     * 并发安全：通过 LAST_INSERT_ID(expr) 技巧，连接级变量天然隔离
     *   - UPDATE 单语句原子（InnoDB 行锁串行化）
     *   - LAST_INSERT_ID(expr) 写入当前连接变量，其他连接读不到也影响不了
     *   - autocommit 模式也安全，多人同时新增/导入不重号
     * Excel 导入路径不复用此方法，而是通过 def_import_config.前处理模块
     * 配置 sp_邀约_导入前处理($源表, @out) 批量赋号，确保两条路径共用同一发号核心。
     *
     * @param int    $count   本次需要的编码数量（页面新增=1）
     * @param string $bizDate 业务日期（ee_store.邀约日期），空则用今天
     * @return string 候选人编码
     */
    private function generateCandidateCode(int $count = 1, string $bizDate = ''): string
    {
        $date = $bizDate ?: date('Y-m-d');
        // 严格校验日期格式，防 SQL 注入（sp_生成候选人编码 的 p_date 参数）
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        // 初始化会话变量（防残留）
        $this->model->select("SET @seq = 0, @prefix = ''");
        // 调用发号存储过程（LAST_INSERT_ID 防并发，按业务日期分桶）
        $this->model->select(sprintf(
            "CALL sp_生成候选人编码(%d, '%s', @seq, @prefix)",
            $count,
            $date
        ));
        // 读取 OUT 参数（同一会话连接，@变量可见）
        $row = $this->model->select('SELECT @prefix AS p, @seq AS s')->getRowArray() ?: [];
        $prefix = $row['p'] ?? '';
        $seq = (int) ($row['s'] ?? 0);
        return $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * 修改邀约（字段归属路由）
     *
     * - 身份字段（hr_person 归属）→ 更新人员主档（权威源）+ ee_store 兼容双写
     * - 实例字段（ee_store 归属）→ 原 updateRecord 路径
     * - 存量行未挂档时，事务内按行内身份信息回填挂档（查重挂档或建档）
     */
    public function update()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, 'guid')) {
            return $error;
        }

        $guid = $data['guid'];

        // 字段归属路由：身份字段 → hr_person（+兼容双写），实例字段 → ee_store
        $groups = $this->splitDataByFieldOwner('2015', 'ee_store', $data);
        $personData = $groups['hr_person'] ?? [];
        $storeData = $groups['ee_store'] ?? [];

        // 无主档字段变更时保持原路径（纯实例字段修改）
        if (empty($personData)) {
            $data = $this->buildUpdateData($data);
            $num = $this->updateRecord('ee_store', $data, sprintf('GUID="%s"', $guid));

            if ($num > 0) {
                return $this->success(null, '修改邀约信息成功');
            }
            return $this->success(null, '没有需要更新的字段');
        }

        // 读取当前行（身份字段 + 人员编码），用于存量数据回填挂档
        $sql = sprintf(
            'select GUID,人员编码,姓名,身份证号,手机号码,性别,年龄,学校,专业,学历,现住址,工作履历,属地,邀约日期
             from ee_store where GUID=%s limit 1',
            $this->model->quote((string) $guid)
        );
        $row = $this->model->select($sql)->getRowArray();
        if (!$row) {
            return $this->notFound('人员不存在');
        }

        // 过滤为主档实际列
        $personCols = $this->getTableColumns('hr_person');
        $personData = array_intersect_key($personData, array_flip($personCols));

        $personService = new PersonService();
        $num = 0;

        $db = $this->model->getDb();
        $db->transStart();

        try {
            // 存量行未挂档：按行内身份信息查重挂档或新建（行上身份字段含兼容双写副本）
            $personCode = $personService->ensurePersonForStore(
                $row,
                $this->getUserWorkId(),
                (string) ($row['邀约日期'] ?? '')
            );

            // 主档更新（权威源，空值跳过防误清空）
            $personService->updatePersonFields($personCode, $personData, $this->getUserWorkId());

            // 实例表更新（身份字段兼容双写副本 + 人员编码兜底回填）
            $storeData['人员编码'] = $personCode;
            $storeData = $this->buildUpdateData($storeData);
            $num = $this->updateRecord('ee_store', $storeData, sprintf('GUID="%s"', $guid));
        } catch (BusinessException $e) {
            $db->transRollback();
            return $this->businessError($e->getMessage());
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[InvitationApi::update] 事务回滚: ' . $e->getMessage());
            return $this->serverError('修改邀约信息失败');
        }

        $db->transComplete();
        if ($db->transStatus() === false) {
            return $this->serverError('修改邀约信息失败(事务已回滚)');
        }

        if ($num > 0) {
            return $this->success(null, '修改邀约信息成功');
        }
        return $this->success(null, '没有需要更新的字段');
    }

    public function delete()
    {
        $data = $this->getJsonInput();

        if (empty($data['guids']) || !is_array($data['guids'])) {
            return $this->paramError('请选择要删除的人员');
        }

        $guidStr = implode(',', array_map(fn($v) => $this->model->quote((string)$v), $data['guids']));
        $num = $this->deleteRecord('ee_store', sprintf('GUID in (%s)', $guidStr));

        if ($num > 0) {
            return $this->success(null, sprintf('删除成功，共删除 %d 条记录', $num));
        }

        return $this->serverError('删除失败');
    }

    public function transfer()
    {
        $data = $this->getJsonInput();

        if (empty($data['guids']) || !is_array($data['guids'])) {
            return $this->paramError('请选择要转入面试的人员');
        }

        if (empty($data['面试结果'])) {
            return $this->paramError('面试结果不能为空');
        }

        $guidStr = implode(',', array_map(fn($v) => $this->model->quote((string)$v), $data['guids']));

        $interview = match ($data['面试结果']) {
            '通过', '未通过' => '已面试',
            '拒绝' => '拒绝',
            '未面试' => '未面试',
            default => '待面试'
        };

        $sql = sprintf('
            update ee_store
            set 面试信息="%s",
                操作记录="更新,面试信息",操作来源="页面",操作人员="%s",
                结束操作时间="%s",操作时间="%s"
            where GUID in (%s)',
            $interview,
            $this->getUserWorkId(),
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            $guidStr
        );

        // 事务保护：ee_store 状态更新与 ee_interview 转入插入必须同成败，
        // 防止更新成功、插入失败导致记录"已面试"但面试表无数据
        // （对齐 RecordEditService::updateRecord 的事务写法）
        $db = $this->model->getDb();
        $db->transStart();
        $num = 0;

        try {
            $num = $this->model->exec($sql);

            if ($data['面试结果'] === '通过' || $data['面试结果'] === '未通过') {
                $sql = sprintf('
                    insert into ee_interview (
                        候选人编码,
                        姓名,身份证号,手机号码,属地,
                        招聘渠道,渠道类型,渠道名称,
                        面试业务,面试岗位,
                        一次面试日期,一次面试人,一次面试结果,
                        预约培训日期,邀约信息,
                        操作记录,操作来源,操作人员,开始操作时间,
                        有效标识,删除标识)
                    select 候选人编码,
                        姓名,身份证号,手机号码,属地,
                        招聘渠道,渠道类型,渠道名称,
                        邀约业务,邀约岗位,
                        "%s","%s","%s",
                        "%s","通过",
                        "邀约表转入","页面","%s","%s",
                        "1","0"
                    from ee_store
                    where GUID in (%s)',
                    $data['面试日期'] ?? '',
                    $data['面试人'] ?? '',
                    $data['面试结果'],
                    $data['预约培训日期'] ?? '',
                    $this->getUserWorkId(),
                    date('Y-m-d H:i:s'),
                    $guidStr
                );

                $this->model->exec($sql);
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[InvitationApi::transfer] 事务回滚: ' . $e->getMessage());
            return $this->serverError('转入面试失败');
        }

        $db->transComplete();
        if ($db->transStatus() === false) {
            return $this->serverError('转入面试失败(事务已回滚)');
        }

        return $this->success(null, sprintf('更新面试信息成功，更新 %d 条记录', $num));
    }

    public function options()
    {
        // 下拉选项过滤：与 2010 同源（FieldConfigService::getObjectOptions）
        // 用 userContext->getLocation()（员工属地单值），不再用 resolveLocationAuth
        // 的合并赋权字符串（如 "北京,上海|北京,广州"），后者在 locate 子串匹配下几乎
        // 匹配不到任何选项，语义错误。
        $userLocation = $this->userContext->getLocation();

        $regionSql = sprintf('
            select distinct 对象值 as value, 对象值 as label
            from def_object
            where 对象名称="属地" and 有效标识="1"
                and (属地="" or locate(属地,"%s"))
            order by convert(对象值 using gbk)',
            $userLocation
        );

        $channelSql = sprintf('
            select distinct 对象值 as value, 对象值 as label
            from def_object
            where 对象名称="招聘渠道" and 有效标识="1"
                and (属地="" or locate(属地,"%s"))
            order by convert(对象值 using gbk)',
            $userLocation
        );

        $regionResult = $this->model->select($regionSql)->getResultArray();
        $channelResult = $this->model->select($channelSql)->getResultArray();

        return $this->success([
            'region' => $regionResult,
            'channel' => $channelResult,
            'result' => [
                ['value' => '通过', 'label' => '通过'],
                ['value' => '未通过', 'label' => '未通过'],
                ['value' => '考虑', 'label' => '考虑'],
                ['value' => '拒绝', 'label' => '拒绝'],
                ['value' => '未邀约', 'label' => '未邀约']
            ],
            'interviewResult' => [
                ['value' => '通过', 'label' => '通过'],
                ['value' => '未通过', 'label' => '未通过'],
                ['value' => '考虑', 'label' => '考虑'],
                ['value' => '拒绝', 'label' => '拒绝'],
                ['value' => '未面试', 'label' => '未面试']
            ]
        ]);
    }

    /**
     * 构建邀约记录分组聚合树（多级桶聚合）。
     *
     * 算法：按 (招聘渠道, 面试日期, 邀约结果, 面试信息, 属地) 5 个字段做多级桶聚合。
     * 与 buildOrgTree（递归父子）不同：这里不依赖父级编码，是顺序分组聚合。
     *
     * @param array $data 邀约数据（含 GUID/姓名/属地/邀约日期/邀约结果/面试信息/招聘渠道）
     * @return array 聚合后的多级树
     */
    private function buildGroupedInvitationTree(array $data): array
    {
        $up5Arr = [];
        $up4Arr = [];
        $up3Arr = [];
        $up2Arr = [];
        $up1Arr = [];

        foreach ($data as $row) {
            $eeArr = [
                'id' => sprintf('人员^%s^%s', $row['GUID'], $row['姓名']),
                'guid' => $row['GUID'],
                'name' => $row['姓名'],
                'value' => sprintf('%s (%s)', $row['姓名'], $row['邀约日期']),
                'type' => 'person'
            ];

            $up1Id = sprintf('招聘渠道^%s^%s^%s^%s^%s', $row['属地'], $row['邀约结果'], $row['面试信息'], '', $row['招聘渠道']);
            if (!isset($up1Arr[$up1Id])) {
                $up1Arr[$up1Id] = [
                    'id' => $up1Id,
                    'value' => $row['招聘渠道'],
                    'num' => 0,
                    'items' => [],
                    'type' => 'channel'
                ];
            }
            $up1Arr[$up1Id]['num'] = count($up1Arr[$up1Id]['items']) + 1;
            $up1Arr[$up1Id]['value'] = sprintf('%s (%d人)', $row['招聘渠道'], $up1Arr[$up1Id]['num']);
            $up1Arr[$up1Id]['items'][] = $eeArr;
        }

        foreach ($up1Arr as $up1) {
            $arr = explode('^', $up1['id']);
            $up2Id = sprintf('面试日期^%s^%s^%s^%s', $arr[1], $arr[2], $arr[3], $arr[4]);
            if (!isset($up2Arr[$up2Id])) {
                $up2Arr[$up2Id] = [
                    'id' => $up2Id,
                    'value' => '预约面试日期 ' . $arr[4],
                    'num' => 0,
                    'items' => [],
                    'type' => 'date'
                ];
            }
            $up2Arr[$up2Id]['num'] += $up1['num'];
            $up2Arr[$up2Id]['value'] = sprintf('预约面试日期 %s (%d人)', $arr[4], $up2Arr[$up2Id]['num']);
            $up2Arr[$up2Id]['items'][] = $up1;
        }

        foreach ($up2Arr as $up2) {
            $arr = explode('^', $up2['id']);
            $up3Id = sprintf('面试信息^%s^%s^%s', $arr[1], $arr[2], $arr[3]);
            if (!isset($up3Arr[$up3Id])) {
                $up3Arr[$up3Id] = [
                    'id' => $up3Id,
                    'value' => $arr[3],
                    'num' => 0,
                    'items' => [],
                    'type' => 'interview'
                ];
            }
            $up3Arr[$up3Id]['num'] += $up2['num'];
            $up3Arr[$up3Id]['value'] = sprintf('%s (%d人)', $arr[3], $up3Arr[$up3Id]['num']);
            $up3Arr[$up3Id]['items'][] = $up2;
        }

        foreach ($up3Arr as $up3) {
            $arr = explode('^', $up3['id']);
            $up4Id = sprintf('邀约结果^%s^%s', $arr[1], $arr[2]);
            if (!isset($up4Arr[$up4Id])) {
                $up4Arr[$up4Id] = [
                    'id' => $up4Id,
                    'value' => $arr[2],
                    'num' => 0,
                    'items' => [],
                    'type' => 'result'
                ];
            }
            $up4Arr[$up4Id]['num'] += $up3['num'];
            $up4Arr[$up4Id]['value'] = sprintf('%s (%d人)', $arr[2], $up4Arr[$up4Id]['num']);
            $up4Arr[$up4Id]['items'][] = $up3;
        }

        foreach ($up4Arr as $up4) {
            $arr = explode('^', $up4['id']);
            $up5Id = sprintf('属地^%s', $arr[1]);
            if (!isset($up5Arr[$up5Id])) {
                $up5Arr[$up5Id] = [
                    'id' => $up5Id,
                    'value' => $arr[1],
                    'num' => 0,
                    'items' => [],
                    'type' => 'region'
                ];
            }
            $up5Arr[$up5Id]['num'] += $up4['num'];
            $up5Arr[$up5Id]['value'] = sprintf('%s (%d人)', $arr[1], $up5Arr[$up5Id]['num']);
            $up5Arr[$up5Id]['items'][] = $up4;
        }

        $csrArr = [
            'id' => '0级^邀约人员',
            'value' => '邀约人员',
            'items' => [],
            'type' => 'root'
        ];

        $csrNum = 0;
        foreach ($up5Arr as $up5) {
            $csrNum += $up5['num'];
            $csrArr['items'][] = $up5;
        }
        $csrArr['value'] = sprintf('邀约人员 (%d人)', $csrNum);

        return [$csrArr];
    }
}
