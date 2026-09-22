<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Services\Application\ApplicationService;
use App\Services\Application\StageTransferService;
use App\Exceptions\AuthException;
use App\Exceptions\BusinessException;
use App\Exceptions\ValidationException;
use App\Services\Person\CandidateCodeService;
use App\Services\Person\PersonService;

class InterviewApi extends BaseApiController
{
    public function tree()
    {
        // 属地权限：与 2010 同源（走 ContextService，含部门授权优先、upkeepAuth）
        $locationAuthzCond = $this->resolveLocationAuthzCond('2025');
        if ($locationAuthzCond === null) {
            return $this->serverError('无法获取属地权限');
        }

        $sql = sprintf('
            select GUID,姓名,身份证号,手机号码,属地,
                if(mod(substr(身份证号,17,1),2)=0,"女","男") as 性别,
                招聘渠道,一次面试结果 as 面试结果,
                if(参培信息="","待参培",参培信息) as 参培信息,
                一次面试日期 as 面试日期,预约培训日期
            from ee_interview
            where %s and 有效标识="1" and 删除标识="0"
            order by 属地,field(面试结果,"未面试","通过","未通过","转面其他岗位"),
                field(参培信息,"待参培","已参培","未参培"),
                招聘渠道,预约培训日期 desc,convert(姓名 using gbk)',
            $locationAuthzCond);

        $results = $this->model->select($sql)->getResultArray();
        $tree = $this->buildGroupedInterviewTree($results);

        return $this->success($tree);
    }

    /**
     * 调试：打印左侧面试树加载的完整 SQL + 分段耗时
     *
     * 权限：与 pageMeta.toolbar.debugSql 同源（hasDebugSqlAuth）
     * 属地权限：与 tree() 同源、与 2010 完全一致
     */
    public function debugTree()
    {
        if (! $this->hasDebugSqlAuth()) {
            return $this->serverError('无调试权限');
        }

        $totalStart = hrtime(true);

        // 1. 构建工作台上下文（与 tree() 同源，与 2010 完全一致）
        $contextStart = hrtime(true);
        try {
            [$context] = $this->getContextService()->buildWorkbenchContext('2025');
        } catch (AuthException | BusinessException | ValidationException $e) {
            log_message('error', '[InterviewApi::debugTree] 构建上下文失败: ' . $e->getMessage());
            return $this->serverError('无法获取属地权限: ' . $e->getMessage());
        }
        $locationAuthzCond = (string) ($context['locationAuthzCond'] ?? '');
        if ($locationAuthzCond === '') {
            $locationAuthzCond = '1=1';
        }
        $userLocationAuth = (string) ($context['user']['locationAuth'] ?? '');
        $deptAuthzCond    = (string) ($context['deptAuthzCond'] ?? '');
        $contextEnd = hrtime(true);

        // 2. 构建 SQL（与 tree() 完全一致）
        $sql = sprintf('
            select GUID,姓名,身份证号,手机号码,属地,
                if(mod(substr(身份证号,17,1),2)=0,"女","男") as 性别,
                招聘渠道,一次面试结果 as 面试结果,
                if(参培信息="","待参培",参培信息) as 参培信息,
                一次面试日期 as 面试日期,预约培训日期
            from ee_interview
            where %s and 有效标识="1" and 删除标识="0"
            order by 属地,field(面试结果,"未面试","通过","未通过","转面其他岗位"),
                field(参培信息,"待参培","已参培","未参培"),
                招聘渠道,预约培训日期 desc,convert(姓名 using gbk)',
            $locationAuthzCond);

        // 3. 执行查询
        $queryStart = hrtime(true);
        $results = $this->model->select($sql)->getResultArray();
        $queryEnd = hrtime(true);

        // 4. 构建树
        $buildStart = hrtime(true);
        $tree = $this->buildGroupedInterviewTree($results);
        $buildEnd = hrtime(true);

        $totalEnd = hrtime(true);

        return $this->success([
            'sql'                     => $sql,
            'locationAuthzCondition'  => $locationAuthzCond,
            'userLocationAuth'        => $userLocationAuth,
            'deptAuthzCondition'      => $deptAuthzCond,
            'rowCount'                => count($results),
            'treeNodeCount'           => count($tree),
            'timing' => [
                'contextBuildMs' => round(($contextEnd - $contextStart) / 1e6, 2),
                'queryMs'        => round(($queryEnd - $queryStart) / 1e6, 2),
                'buildTreeMs'    => round(($buildEnd - $buildStart) / 1e6, 2),
                'totalMs'        => round(($totalEnd - $totalStart) / 1e6, 2),
            ],
        ]);
    }

    public function detail($guid = '')
    {
        if (empty($guid)) {
            $guid = $this->getGuidFromRequest();
        }

        if (empty($guid)) {
            return $this->paramError('人员GUID不能为空');
        }

        $selectFields = $this->buildDetailSelectFields('2025', 'ee_interview');

        $sql = sprintf('
            select %s
            from ee_interview
            where GUID="%s" and 有效标识="1" and 删除标识="0"',
            $selectFields,
            $guid);

        $result = $this->model->select($sql)->getRowArray();

        if (!$result) {
            return $this->notFound('人员不存在');
        }

        // 历史投递链：同人员编码的全部流程实例（含当前），供详情页只读展示
        // 岗位取 ee_store.邀约岗位（实例起点岗位），结论取 ee_interview 转面行
        // 人员编码单独取（detail 配置驱动 SELECT 不保证含该列）
        $locator = $this->model->select(sprintf(
            'select 人员编码 from ee_interview where GUID=%s and 有效标识="1" and 删除标识="0" limit 1',
            $this->model->quote($guid)
        ))->getRowArray();
        $result['applicationHistory'] = $this->loadApplicationHistory(
            (string) ($locator['人员编码'] ?? '')
        );

        return $this->success($result);
    }

    /**
     * 查询同人员编码的历史投递链（ee_application 为主轴）
     *
     * @param string $personCode 人员编码（空则返回空数组）
     * @return array 按邀约日期升序的实例列表
     */
    private function loadApplicationHistory(string $personCode): array
    {
        if ($personCode === '') {
            return [];
        }

        $sql = sprintf(
            'select a.候选人编码, a.当前阶段, a.终止原因, a.邀约日期,
                a.参培日期, a.入职日期, a.转自候选人编码,
                ifnull(s.邀约岗位, "") as 邀约岗位, ifnull(s.邀约业务, "") as 邀约业务,
                ifnull(s.备注说明, "") as 备注说明,
                ifnull(i.一次面试结果, "") as 面试结果,
                ifnull(i.一次面试人, "") as 面试人,
                ifnull(i.一次面试日期, "") as 面试日期
            from ee_application a
            left join ee_store s
                on s.候选人编码 = a.候选人编码 and s.有效标识 = "1" and s.删除标识 = "0"
            left join ee_interview i
                on i.候选人编码 = a.候选人编码 and i.有效标识 = "1" and i.删除标识 = "0"
            where a.人员编码 = %s and a.有效标识 = "1" and a.删除标识 = "0"
            order by a.邀约日期, a.GUID',
            $this->model->quote($personCode)
        );

        return $this->model->select($sql)->getResultArray() ?: [];
    }

    public function add()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, '姓名')) {
            return $error;
        }
        // 手机号码为人员主档建档必填（与邀约新增一致）
        if ($error = $this->requireParam($data, '手机号码')) {
            return $error;
        }

        $data = $this->buildInsertData($data);
        $bizDate = (string) ($data['一次面试日期'] ?? '');

        $db = $this->model->getDb();
        $db->transStart();

        try {
            // 人员主档裁决（自动档，与邀约修改路径同语义）：证件号硬命中/唯一软命中挂既有档，
            // 无命中新建档；多条软命中走新建（重复档由唯一索引/合并机制事后收口）。
            // 直接面试即链路起点，需建档挂档后才能沿链路流转。
            $data['人员编码'] = (new PersonService())->ensurePersonForStore(
                $data, $this->getUserWorkId(), $bizDate
            );

            // 候选人编码：直接面试即链路起点，发新码（按一次面试日期分桶，空则今天）
            $candidateCodeService = new CandidateCodeService();
            $data['候选人编码'] = $candidateCodeService->generateOne($bizDate);

            $num = $this->insertRecord('ee_interview', $data);
            if ($num <= 0) {
                throw new BusinessException('新增面试信息失败');
            }
        } catch (BusinessException $e) {
            $db->transRollback();
            return $this->businessError($e->getMessage());
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[InterviewApi::add] 事务回滚: ' . $e->getMessage());
            return $this->serverError('新增面试信息失败');
        }

        $db->transComplete();
        if ($db->transStatus() === false) {
            // 生产环境 DBDebug=false 时 SQL 失败不抛异常，兜底检测事务状态
            return $this->serverError('新增面试信息失败(事务已回滚)');
        }

        return $this->success(['人员编码' => $data['人员编码']], '新增面试信息成功');
    }

    public function update()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, 'guid')) {
            return $error;
        }

        $guid = $data['guid'];
        $data = $this->buildUpdateData($data);
        $num = $this->updateRecord('ee_interview', $data, sprintf('GUID="%s"', $guid));

        if ($num > 0) {
            return $this->success(null, '修改面试信息成功');
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
        $num = $this->deleteRecord('ee_interview', sprintf('GUID in (%s)', $guidStr));

        if ($num > 0) {
            return $this->success(null, sprintf('删除成功，共删除 %d 条记录', $num));
        }

        return $this->serverError('删除失败');
    }

    public function transfer()
    {
        $data = $this->getJsonInput();

        if (empty($data['guids']) || !is_array($data['guids'])) {
            return $this->paramError('请选择要转入培训的人员');
        }

        if (empty($data['参培信息'])) {
            return $this->paramError('参培信息不能为空');
        }

        $guidStr = implode(',', array_map(fn($v) => $this->model->quote((string)$v), $data['guids']));

        $sql = sprintf('
            update ee_interview
            set 参培信息="%s",
                操作记录="更新,参培信息",操作来源="页面",操作人员="%s",
                结束操作时间="%s",操作时间="%s"
            where GUID in (%s)',
            $data['参培信息'],
            $this->getUserWorkId(),
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            $guidStr
        );

        // 事务保护：ee_interview 状态更新与 ee_train 转入插入必须同成败
        // （对齐 InvitationApi::transfer 的事务写法）
        $db = $this->model->getDb();

        // guids → 候选人编码（实例状态机定位键）
        $codeRows = $this->model->select(
            'SELECT DISTINCT 候选人编码 FROM ee_interview WHERE GUID IN (' . $guidStr . ')'
        )->getResultArray();
        $candidateCodes = array_values(array_filter(
            array_column($codeRows, '候选人编码'),
            fn($v) => $v !== '' && $v !== null
        ));

        $db->transStart();
        $num = 0;

        try {
            $num = $this->model->exec($sql);

            if ($data['参培信息'] === '已参培') {
                $startTime = date('Y-m-d H:i:s');

                // 业务字段映射走 def_stage_transfer 配置（培训状态"在培"为配置固定值），
                // 系统审计列代码侧追加
                // 候选人编码/人员编码 沿链路继承（原 初始编码 列已随表结构瘦身移除）
                $sql = (new StageTransferService())->buildInsertSelect(
                    'ee_interview',
                    'ee_train',
                    'ee_interview as t1',
                    't1.GUID in (' . $guidStr . ')',
                    $data,
                    [
                        '操作记录'   => '"面试表转入"',
                        '操作来源'   => '"页面"',
                        '操作人员'   => $this->model->quote($this->getUserWorkId()),
                        '开始操作时间' => $this->model->quote($startTime),
                        '有效标识'   => '"1"',
                        '删除标识'   => '"0"',
                    ]
                );

                $this->model->exec($sql);

                // 实例状态机（阶段②A）：与阶段表写入同事务
                // 仅"已参培"流转到培训；其他参培信息（未参培等）实例留在面试阶段，
                // 终止口径待业务确认后接入（问题1选B）
                if (!empty($candidateCodes)) {
                    (new ApplicationService())->transferStage(
                        $candidateCodes,
                        '培训',
                        $this->getUserWorkId(),
                        ['参培日期' => (string) ($data['培训开始日期'] ?? '')]
                    );
                }
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[InterviewApi::transfer] 事务回滚: ' . $e->getMessage());
            return $this->serverError('转入培训失败');
        }

        $db->transComplete();
        if ($db->transStatus() === false) {
            return $this->serverError('转入培训失败(事务已回滚)');
        }

        return $this->success(null, sprintf('更新参培信息成功，更新 %d 条记录', $num));
    }

    /**
     * 转面其他岗位（方案A：转投=新实例+血缘关联）
     *
     * 组合动作（单事务）：
     * 1. 旧面试行写转面结论：一次面试结果=转面其他岗位（仅结果标记）
     * 2. 旧实例终止：transferStage 面试→终止（终止原因=转投其他岗位）
     * 3. 逐人发新候选人编码 → INSERT ee_store（邀约业务=转面业务、邀约岗位=转面岗位、备注说明，回到邀约起点）
     * 4. createInstance 新实例 + 回填 转自候选人编码（血缘）
     *
     * 在途实例校验：同人员编码存在其他未终止实例时返回 needConfirm，
     * 前端二次确认后带 force=true 放行。
     */
    public function transferPosition()
    {
        $data = $this->getJsonInput();

        if (empty($data['guids']) || !is_array($data['guids'])) {
            return $this->paramError('请选择要转面其他岗位的人员');
        }
        if (empty($data['转面业务'])) {
            return $this->paramError('转面业务不能为空');
        }
        if (empty($data['转面岗位'])) {
            return $this->paramError('转面岗位不能为空');
        }
        if (trim((string) ($data['备注说明'] ?? '')) === '') {
            return $this->paramError('备注说明不能为空');
        }

        $force = !empty($data['force']);
        $transferBiz = trim((string) $data['转面业务']);
        $transferPosition = trim((string) $data['转面岗位']);
        $remarkNote = trim((string) $data['备注说明']);

        $guidStr = implode(',', array_map(fn($v) => $this->model->quote((string)$v), $data['guids']));

        // 读取面试行（实例定位键 + 建新邀约行所需身份/渠道字段）
        $rows = $this->model->select(sprintf(
            'select GUID,候选人编码,人员编码,姓名,身份证号,手机号码,属地,
                招聘渠道,渠道类型,渠道名称
            from ee_interview
            where GUID in (%s) and 有效标识="1" and 删除标识="0"',
            $guidStr
        ))->getResultArray();

        if (empty($rows)) {
            return $this->notFound('人员不存在');
        }

        // 候选人编码缺失（历史数据）无法走实例状态机，明确拒绝
        foreach ($rows as $row) {
            if (trim((string) ($row['候选人编码'] ?? '')) === '') {
                return $this->businessError(
                    sprintf('人员[%s]的面试记录无候选人编码（历史数据），无法转投', $row['姓名'])
                );
            }
        }

        $candidateCodes = array_values(array_filter(
            array_column($rows, '候选人编码'),
            fn($v) => $v !== '' && $v !== null
        ));

        // 在途实例校验（同人员编码的其他未终止实例）
        if (!$force) {
            $personCodes = array_values(array_unique(array_filter(
                array_column($rows, '人员编码'),
                fn($v) => $v !== '' && $v !== null
            )));
            $conflicts = (new ApplicationService())->findActiveInstances($personCodes, $candidateCodes);
            if (!empty($conflicts)) {
                return $this->error(ApiCode::BUSINESS_ERROR, '选中人员存在进行中的投递流程，请确认是否继续转投', [
                    'needConfirm' => true,
                    'confirmType' => 'activeInstance',
                    'matches' => $conflicts,
                ]);
            }
        }

        $operator = $this->getUserWorkId();
        $today = date('Y-m-d');

        $db = $this->model->getDb();
        $db->transStart();
        $newCodes = [];

        try {
            $applicationService = new ApplicationService();
            $candidateCodeService = new CandidateCodeService();

            // 1. 旧面试行写转面结论（仅结果标记，转面详情由 ee_store 承载）
            $this->updateRecord('ee_interview', $this->buildUpdateData([
                '一次面试结果' => '转面其他岗位',
            ]), sprintf('GUID in (%s)', $guidStr));

            // 2. 旧实例终止（状态机：面试→终止；已终止实例抛异常整体回滚，天然防重复转投）
            $applicationService->transferStage(
                $candidateCodes,
                '终止',
                $operator,
                ['终止原因' => '转投其他岗位', '终止日期' => $today]
            );

            // 3. 逐人：发新码 → 新邀约行 → 新实例 → 血缘回填
            foreach ($rows as $row) {
                $personCode = (string) ($row['人员编码'] ?? '');
                $newCode = $candidateCodeService->generateOne($today);

                $storeData = $this->buildInsertData([
                    '候选人编码' => $newCode,
                    '人员编码' => $personCode,
                    '姓名' => (string) $row['姓名'],
                    '身份证号' => (string) ($row['身份证号'] ?? ''),
                    '手机号码' => (string) ($row['手机号码'] ?? ''),
                    '属地' => (string) ($row['属地'] ?? ''),
                    '招聘渠道' => (string) ($row['招聘渠道'] ?? ''),
                    '渠道类型' => (string) ($row['渠道类型'] ?? ''),
                    '渠道名称' => (string) ($row['渠道名称'] ?? ''),
                    '邀约业务' => $transferBiz,
                    '邀约岗位' => $transferPosition,
                    '备注说明' => $remarkNote,
                    '邀约日期' => $today,
                    '邀约结果' => '未邀约',
                    '邀约次数' => 1,
                ]);
                $num = $this->insertRecord('ee_store', $storeData);
                if ($num <= 0) {
                    throw new BusinessException('新增邀约信息失败');
                }

                // 新实例（阶段②A 双 INSERT 闭环）+ 血缘回填（同事务）
                $applicationService->createInstance($newCode, $personCode, $today, $operator);
                $this->model->exec(sprintf(
                    'update ee_application set 转自候选人编码=%s
                     where 候选人编码=%s and 转自候选人编码=""',
                    $this->model->quote((string) $row['候选人编码']),
                    $this->model->quote($newCode)
                ));

                $newCodes[] = $newCode;
            }
        } catch (BusinessException $e) {
            $db->transRollback();
            return $this->businessError($e->getMessage());
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[InterviewApi::transferPosition] 事务回滚: ' . $e->getMessage());
            return $this->serverError('转面其他岗位失败');
        }

        $db->transComplete();
        if ($db->transStatus() === false) {
            return $this->serverError('转面其他岗位失败(事务已回滚)');
        }

        return $this->success(
            ['候选人编码' => $newCodes],
            sprintf('转面成功，已生成新投递 %d 条（邀约业务：%s，邀约岗位：%s）', count($newCodes), $transferBiz, $transferPosition)
        );
    }

    public function options()
    {
        // 下拉选项过滤：与 2010 同源（FieldConfigService::getObjectOptions）
        // 用 userContext->getLocation()（员工属地单值），不再用 resolveLocationAuth
        // 的合并赋权字符串（locate 子串匹配语义错误）
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

        $trainBizSql = sprintf('
            select distinct 对象值 as value, 对象值 as label
            from def_object
            where 对象名称="培训业务" and 有效标识="1"
                and (属地="" or locate(属地,"%s"))
            order by convert(对象值 using gbk)',
            $userLocation
        );

        // 转面业务选项：与邀约页"邀约业务"同源（def_object）
        $bizSql = sprintf('
            select distinct 对象值 as value, 对象值 as label
            from def_object
            where 对象名称="邀约业务" and 有效标识="1"
                and (属地="" or locate(属地,"%s"))
            order by convert(对象值 using gbk)',
            $userLocation
        );

        // 转面岗位选项：与邀约页"邀约岗位"同源，带上级对象信息供前端级联过滤
        $positionSql = sprintf('
            select distinct 对象值 as value, 对象值 as label,
                上级对象名称 as parentName, 上级对象值 as parentValue
            from def_object
            where 对象名称="邀约岗位" and 有效标识="1"
                and (属地="" or locate(属地,"%s"))
            order by convert(对象值 using gbk)',
            $userLocation
        );

        $regionResult = $this->model->select($regionSql)->getResultArray();
        $channelResult = $this->model->select($channelSql)->getResultArray();
        $trainBizResult = $this->model->select($trainBizSql)->getResultArray();
        $bizResult = $this->model->select($bizSql)->getResultArray();
        $positionResult = $this->model->select($positionSql)->getResultArray();

        return $this->success([
            'region' => $regionResult,
            'channel' => $channelResult,
            'trainBiz' => $trainBizResult,
            'biz' => $bizResult,
            'position' => $positionResult,
            'interviewResult' => [
                ['value' => '通过', 'label' => '通过'],
                ['value' => '未通过', 'label' => '未通过'],
                ['value' => '考虑', 'label' => '考虑'],
                ['value' => '拒绝', 'label' => '拒绝'],
                ['value' => '未面试', 'label' => '未面试'],
                ['value' => '转面其他岗位', 'label' => '转面其他岗位']
            ],
            'trainStatus' => [
                ['value' => '已参培', 'label' => '已参培'],
                ['value' => '未参培', 'label' => '未参培']
            ]
        ]);
    }

    /**
     * 构建面试记录分组聚合树（多级桶聚合）。
     *
     * 算法：按 (招聘渠道, 培训日期, 面试结果, 参培信息, 属地) 5 个字段做多级桶聚合。
     * 与 buildOrgTree（递归父子）不同：这里不依赖父级编码，是顺序分组聚合。
     *
     * @param array $data 面试数据（含 GUID/姓名/属地/面试日期/面试结果/参培信息/预约培训日期/招聘渠道）
     * @return array 聚合后的多级树
     */
    private function buildGroupedInterviewTree(array $data): array
    {
        $up5Arr = [];
        $up4Arr = [];
        $up3Arr = [];
        $up2Arr = [];
        $up1Arr = [];

        foreach ($data as $row) {
            // 转面标识：转面其他岗位的人员节点追加标记，便于在树上识别
            $personValue = sprintf('%s (%s)', $row['姓名'], $row['面试日期']);
            if (($row['面试结果'] ?? '') === '转面其他岗位') {
                $personValue .= ' [转面]';
            }

            $eeArr = [
                'id' => sprintf('人员^%s^%s', $row['GUID'], $row['姓名']),
                'guid' => $row['GUID'],
                'name' => $row['姓名'],
                'value' => $personValue,
                'type' => 'person'
            ];

            $up1Id = sprintf('招聘渠道^%s^%s^%s^%s^%s', $row['属地'], $row['面试结果'], $row['参培信息'], $row['预约培训日期'], $row['招聘渠道']);
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
            $up2Id = sprintf('培训日期^%s^%s^%s^%s', $arr[1], $arr[2], $arr[3], $arr[4]);
            if (!isset($up2Arr[$up2Id])) {
                $up2Arr[$up2Id] = [
                    'id' => $up2Id,
                    'value' => '预约培训日期 ' . $arr[4],
                    'num' => 0,
                    'items' => [],
                    'type' => 'date'
                ];
            }
            $up2Arr[$up2Id]['num'] += $up1['num'];
            $up2Arr[$up2Id]['value'] = sprintf('预约培训日期 %s (%d人)', $arr[4], $up2Arr[$up2Id]['num']);
            $up2Arr[$up2Id]['items'][] = $up1;
        }

        foreach ($up2Arr as $up2) {
            $arr = explode('^', $up2['id']);
            $up3Id = sprintf('参培信息^%s^%s^%s', $arr[1], $arr[2], $arr[3]);
            if (!isset($up3Arr[$up3Id])) {
                $up3Arr[$up3Id] = [
                    'id' => $up3Id,
                    'value' => $arr[3],
                    'num' => 0,
                    'items' => [],
                    'type' => 'train'
                ];
            }
            $up3Arr[$up3Id]['num'] += $up2['num'];
            $up3Arr[$up3Id]['value'] = sprintf('%s (%d人)', $arr[3], $up3Arr[$up3Id]['num']);
            $up3Arr[$up3Id]['items'][] = $up2;
        }

        foreach ($up3Arr as $up3) {
            $arr = explode('^', $up3['id']);
            $up4Id = sprintf('面试结果^%s^%s', $arr[1], $arr[2]);
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
            'id' => '0级^面试人员',
            'value' => '面试人员',
            'items' => [],
            'type' => 'root'
        ];

        $csrNum = 0;
        foreach ($up5Arr as $up5) {
            $csrNum += $up5['num'];
            $csrArr['items'][] = $up5;
        }
        $csrArr['value'] = sprintf('面试人员 (%d人)', $csrNum);

        return [$csrArr];
    }
}
