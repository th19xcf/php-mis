<?php

namespace App\Services\Employee;

use App\Models\Mcommon;
use App\Services\Application\ApplicationService;
use RuntimeException;

/**
 * ee_employment 雇佣记录表存量迁移服务（阶段③第①步）
 *
 * 源：ee_onjob 有效行（有效标识='1' AND 删除标识='0'，实测 6666 行）。
 * 设计决策（2026-09-03 计划确认）：
 *   - SCD2 失效版本行（11464 条）不迁移，随 ee_onjob 原地归档
 *   - 入职次数派生：按 人员编码 分区、记录开始日期（归一化）+GUID 排序
 *     的 ROW_NUMBER + 目标表既有行数（兼容增量重跑）
 *   - 生命周期日期（记录开始/记录结束/离职）经多格式归一化转 DATE，
 *     脏值落 NULL 计入 dateParseFailures 报告暴露
 *   - 幂等键：NOT EXISTS (人员编码 + 候选人编码)，重复执行跳过已迁行
 *
 * 三路分流（源行按人员编码就绪性）：
 *   A 直迁：人员编码非空直接映射
 *   B 补挂：人员编码空、身份证号非空且命中主档 → 同事务回填 ee_onjob.人员编码
 *   C 无法归并（证件号空/无主档命中）：计入 unableRows，提示先跑 person:migrate
 *
 * 读一律走 getDb()->query()（Mcommon::select 有请求级缓存，迁移过程中
 * 写后读须绕开缓存）；写走同连接保证事务边界。
 */
class EmploymentMigrateService
{
    private Mcommon $model;

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * @param bool        $dryRun   true=只出报告不写库
     * @param int         $batch    每批事务行数
     * @param string      $operator 操作人员工号
     * @param callable|null $progress 进度回调 fn(int $done, int $total, string $stage)
     * @param bool        $fixStage 是否修复状态失真（当前阶段=入职 但雇佣记录已离职）
     * @return array 迁移报告
     */
    public function migrate(bool $dryRun, int $batch, string $operator, ?callable $progress = null, bool $fixStage = false): array
    {
        $report = [
            'generatedAt' => date('Y-m-d H:i:s'),
            'dryRun'      => $dryRun,
            'sourceRows'  => 0,
            'directRows'  => 0,
            'attachRows'  => 0,
            'unableRows'  => 0,
            'migrated'    => 0,
            'backfilled'  => 0,
            'multiChainPersons' => 0,
            'seqMismatch'      => 0,
            'dateParseFailures' => [],
            'unableSamples'     => [],
            'fixStage'          => ['applied' => 0, 'skipped' => 0, 'codes' => []],
        ];

        // ---------- 1. 源行解析（人员编码就绪性三路分流） ----------
        // 解析顺序：人员编码(派生序号的分区键) → 归一化记录开始日期(序内排序键) → GUID
        $sourceRows = $this->query($this->sourceSql(''))->getResultArray();
        $report['sourceRows'] = count($sourceRows);

        $pending = [];
        foreach ($sourceRows as $row) {
            if ($row['resolvedCode'] !== '') {
                if ($row['srcCode'] === '') {
                    $report['attachRows']++;
                } else {
                    $report['directRows']++;
                }
                $pending[] = $row;
            } else {
                $report['unableRows']++;
                if (count($report['unableSamples']) < 20) {
                    $report['unableSamples'][] = [
                        'GUID'      => (int) $row['GUID'],
                        '姓名'      => $row['姓名'],
                        '身份证号'  => $this->maskId($row['身份证号']),
                        '员工状态'  => $row['员工状态'],
                        '候选人编码' => $row['srcCode'],
                    ];
                }
            }
        }

        // 多链人员数（同一 resolvedCode 多行 = 一人多次雇佣）
        $chainCount = [];
        foreach ($pending as $row) {
            $chainCount[$row['resolvedCode']] = ($chainCount[$row['resolvedCode']] ?? 0) + 1;
        }
        $report['multiChainPersons'] = count(array_filter($chainCount, static fn($c) => $c > 1));

        // ---------- 2. 派生序号 vs 手填差异（报告暴露用） ----------
        // 全集 ROW_NUMBER 口径与执行时逐批（COUNT+ROW_NUMBER）一致：
        // 批次按全集序切片，同一人员各批保持日期序，跨批累计值一致
        $report['seqMismatch'] = $this->countSeqMismatch($pending);

        // ---------- 3. 日期解析失败统计 ----------
        $report['dateParseFailures'] = $this->countDateParseFailures($pending);

        if ($dryRun) {
            $report['expectedMigrate'] = count($pending);
            if ($fixStage) {
                $report['fixStage']['skipped'] = $this->countStageDiverged();
            }
            return $report;
        }

        // ---------- 4. 正式执行：B 补挂回填 → 分批 INSERT ----------
        $db = $this->model->getDb();
        $total = count($pending);
        $done  = 0;

        foreach (array_chunk($pending, max(1, $batch)) as $chunk) {
            $db->transStart();
            try {
                // 4a. B 类补挂：ee_onjob.人员编码 回填（幂等：仅空值行）
                $this->backfillPersonCode($chunk, $report);

                // 4b. INSERT...SELECT（含派生入职次数，幂等 NOT EXISTS）
                $guids = implode(',', array_map(static fn($r) => (int) $r['GUID'], $chunk));
                $sql = $this->insertSelectSql($guids);
                $inserted = $this->model->exec($sql);
                if ($inserted < 0) {
                    throw new RuntimeException('ee_employment 批量迁入失败: GUID in (' . substr($guids, 0, 50) . '...)');
                }
                $report['migrated'] += $inserted;
            } catch (\Throwable $e) {
                $db->transRollback();
                throw $e;
            }
            $db->transComplete();
            if ($db->transStatus() === false) {
                throw new RuntimeException('迁移批次事务失败（已回滚）');
            }

            $done += count($chunk);
            if ($progress !== null) {
                $progress($done, $total, 'migrate');
            }
        }

        // ---------- 5. 状态失真修复（--fix-stage） ----------
        if ($fixStage) {
            $report['fixStage'] = $this->fixStage($operator, $progress);
        }

        // ---------- 6. 回填行数（B 类实际生效数） ----------
        $report['backfilled'] = $this->countBackfilled();

        return $report;
    }

    // ------------------------------------------------------------
    // SQL 构建
    // ------------------------------------------------------------

    /**
     * 源行解析 SQL（含主档补挂 JOIN 与全排序键）
     *
     * @param string $whereExtra 附加 WHERE（空串=全量）
     */
    private function sourceSql(string $whereExtra): string
    {
        $normStart  = $this->dateNormExpr('记录开始日期');
        $normResign = $this->dateNormExpr('离职日期');
        $normEnd    = $this->dateNormExpr('记录结束日期');

        // raw* 原值列用于区分"源空"与"归一化解析失败"（报告口径）
        return "
            SELECT s.GUID,
                   IFNULL(s.候选人编码, '') AS srcCode,
                   IFNULL(s.人员编码, '')   AS srcPerson,
                   COALESCE(NULLIF(s.人员编码, ''), p.人员编码, '') AS resolvedCode,
                   s.姓名, s.身份证号, s.员工状态, s.入职次数,
                   NULLIF(s.记录开始日期, '') AS rawStart,
                   NULLIF(s.离职日期, '')     AS rawResign,
                   NULLIF(s.记录结束日期, '') AS rawEnd,
                   {$normStart}  AS normStart,
                   {$normResign} AS normResign,
                   {$normEnd}    AS normEnd
            FROM ee_onjob s
            LEFT JOIN hr_person p
                ON NULLIF(s.身份证号, '') IS NOT NULL
               AND p.身份证号 = s.身份证号
               AND p.有效标识 = '1' AND p.删除标识 = '0'
               AND p.合并至GUID IS NULL
            WHERE s.有效标识 = '1' AND s.删除标识 = '0'
              {$whereExtra}
            ORDER BY resolvedCode, normStart, s.GUID";
    }

    /**
     * ee_onjob VARCHAR 多格式日期 → DATE 归一化转换链
     * 口径与 ApplicationReconcile::dateNormExpr 一致
     */
    private function dateNormExpr(string $field): string
    {
        $v = "NULLIF(s.`{$field}`, '')";

        return "COALESCE(STR_TO_DATE({$v}, '%Y-%m-%d'), STR_TO_DATE({$v}, '%Y/%m/%d'), "
            . "STR_TO_DATE({$v}, '%m/%d/%Y'), STR_TO_DATE({$v}, '%Y%m%d'), "
            . "STR_TO_DATE(REPLACE(REPLACE({$v}, '年', '-'), '日', ''), '%Y-%m-%d'))";
    }

    /**
     * 审计时间列 → DATETIME
     *
     * ee_onjob 实测列类型（INFORMATION_SCHEMA 2026-09-03）：
     *   操作时间 = TIMESTAMP（透传，与 '' 比较会触发 1292 Incorrect datetime value）
     *   开始/结束操作时间 = VARCHAR（STR_TO_DATE 归一化，脏值落 NULL）
     */
    private function dateTimeNormExpr(string $field): string
    {
        if ($field === '操作时间') {
            return 's.`操作时间`';
        }
        return "STR_TO_DATE(NULLIF(s.`{$field}`, ''), '%Y-%m-%d %H:%i:%s')";
    }

    /**
     * 分批迁入 INSERT...SELECT（含派生入职次数 + 幂等过滤）
     *
     * 入职次数 = 目标表该人员既有行数 + 本批内 ROW_NUMBER（按记录开始日期序）；
     * 批次切自全集排序（人员编码→日期→GUID），跨批保持日期序，序号全局正确。
     */
    private function insertSelectSql(string $guidList): string
    {
        $normStart  = $this->dateNormExpr('记录开始日期');
        $normEnd    = $this->dateNormExpr('记录结束日期');
        $normResign = $this->dateNormExpr('离职日期');
        $resolved   = 'COALESCE(NULLIF(s.人员编码, \'\'), p.人员编码, \'\')';

        $seq = "(SELECT COUNT(*) FROM ee_employment e WHERE e.人员编码 = {$resolved})"
            . " + ROW_NUMBER() OVER (PARTITION BY {$resolved} ORDER BY {$normStart}, s.GUID)";

        $idempotent = "NOT EXISTS (SELECT 1 FROM ee_employment e2
                          WHERE e2.人员编码 = {$resolved}
                            AND e2.候选人编码 = IFNULL(s.候选人编码, ''))";

        return "
            INSERT INTO ee_employment (
                人员编码, 入职次数, 候选人编码,
                属地, 招聘渠道, 员工类别, 实习结束日期,
                培训信息, 培训开始日期, 培训完成日期, 一阶段日期, 二阶段日期,
                部门编码, 部门名称, 班组, 小组, 岗位名称, 岗位类型, 结算类型,
                工号1, 工号2, 派遣公司, 备注, 员工阶段, 员工状态,
                记录开始日期, 记录结束日期, 离职日期, 离职原因,
                操作记录, 操作来源, 操作人员,
                开始操作时间, 结束操作时间, 操作时间,
                校验标识, 删除标识, 有效标识
            )
            SELECT
                {$resolved}, {$seq}, IFNULL(s.候选人编码, ''),
                s.属地, s.招聘渠道, s.员工类别, s.实习结束日期,
                s.培训信息, s.培训开始日期, s.培训完成日期, s.一阶段日期, s.二阶段日期,
                s.部门编码, s.部门名称, s.班组, s.小组, s.岗位名称, s.岗位类型, s.结算类型,
                s.工号1, s.工号2, s.派遣公司, s.备注, s.员工阶段, s.员工状态,
                {$normStart},
                IF(s.员工状态 = '离职', COALESCE({$normEnd}, {$normResign}), NULL),
                IF(s.员工状态 = '离职', {$normResign}, NULL),
                s.离职原因,
                '存量搬迁', s.操作来源, s.操作人员,
                {$this->dateTimeNormExpr('开始操作时间')},
                {$this->dateTimeNormExpr('结束操作时间')},
                {$this->dateTimeNormExpr('操作时间')},
                '0', '0', '1'
            FROM ee_onjob s
            LEFT JOIN hr_person p
                ON NULLIF(s.身份证号, '') IS NOT NULL
               AND p.身份证号 = s.身份证号
               AND p.有效标识 = '1' AND p.删除标识 = '0'
               AND p.合并至GUID IS NULL
            WHERE s.GUID IN ({$guidList})
              AND {$resolved} <> ''
              AND {$idempotent}";
    }

    // ------------------------------------------------------------
    // 分步实现
    // ------------------------------------------------------------

    /** B 类补挂：按身份证号回填 ee_onjob.人员编码（仅空值行，幂等） */
    private function backfillPersonCode(array $chunk, array &$report): void
    {
        $guids = [];
        foreach ($chunk as $row) {
            if ($row['srcPerson'] === '' && $row['resolvedCode'] !== '') {
                $guids[] = (int) $row['GUID'];
            }
        }
        if (empty($guids)) {
            return;
        }

        $guidList = implode(',', $guids);
        $sql = "
            UPDATE ee_onjob s
            JOIN hr_person p
                ON NULLIF(s.身份证号, '') IS NOT NULL
               AND p.身份证号 = s.身份证号
               AND p.有效标识 = '1' AND p.删除标识 = '0'
               AND p.合并至GUID IS NULL
            SET s.人员编码 = p.人员编码
            WHERE s.GUID IN ({$guidList})
              AND (s.人员编码 IS NULL OR s.人员编码 = '')";
        $this->model->exec($sql);
    }

    /** 状态失真修复：当前阶段=入职 但 ee_employment 已离职 → transferStage(终止) */
    private function fixStage(string $operator, ?callable $progress): array
    {
        $rows = $this->query("
            SELECT a.候选人编码 AS code, em.离职原因 AS reason, em.离职日期 AS resignDate
            FROM ee_application a
            JOIN ee_employment em ON em.候选人编码 = a.候选人编码
            WHERE a.当前阶段 = '入职' AND em.员工状态 = '离职'
            ORDER BY a.候选人编码")->getResultArray();

        $result = ['applied' => 0, 'skipped' => 0, 'codes' => []];
        if (empty($rows)) {
            return $result;
        }

        $app = new ApplicationService();
        $db  = $this->model->getDb();
        $total = count($rows);
        $done = 0;
        $codes = [];

        foreach ($rows as $row) {
            $code = (string) $row['code'];
            // transferStage 无自有事务（调用方包裹）；遇已终止实例抛
            // RuntimeException → 单码回滚，跳过计入 skipped（并发/重复执行场景）
            try {
                $db->transStart();
                $app->transferStage(
                    [$code],
                    '终止',
                    $operator,
                    [
                        '终止原因' => (string) $row['reason'],
                        '终止日期' => (string) ($row['resignDate'] ?? ''),
                    ]
                );
                $db->transComplete();
                if ($db->transStatus() !== false) {
                    $codes[] = $code;
                } else {
                    $result['skipped']++;
                }
            } catch (\Throwable $e) {
                $db->transRollback();
                $result['skipped']++;
            }
            $done++;
            if ($progress !== null) {
                $progress($done, $total, 'fixStage');
            }
        }

        $result['applied'] = count($codes);
        $result['codes'] = $codes;
        // 回滚模板（人工回退用，编码转义防注入）
        if (!empty($codes)) {
            $result['rollbackSql'] = 'UPDATE ee_application SET 当前阶段=\'入职\', 终止原因=\'\', 终止日期=NULL '
                . 'WHERE 候选人编码 IN (' . implode(',', array_map(
                    fn($c) => $db->escape($c),
                    array_slice($codes, 0, 2000)
                )) . ');';
        }

        return $result;
    }

    // ------------------------------------------------------------
    // 报告统计
    // ------------------------------------------------------------

    /** 派生入职次数 vs ee_onjob 手填值差异行数（全集 ROW_NUMBER 口径） */
    private function countSeqMismatch(array $pending): int
    {
        $mismatch = 0;
        $seqByPerson = [];
        // pending 已按 resolvedCode → normStart → GUID 排序（sourceSql ORDER BY）
        foreach ($pending as $row) {
            $seqByPerson[$row['resolvedCode']] = ($seqByPerson[$row['resolvedCode']] ?? 0) + 1;
            if ((int) $seqByPerson[$row['resolvedCode']] !== (int) $row['入职次数']) {
                $mismatch++;
            }
        }
        return $mismatch;
    }

    /** 日期解析失败统计（raw 非空但 norm 为 null = 解析失败；源空不计） */
    private function countDateParseFailures(array $pending): array
    {
        $stats = ['记录开始日期' => 0, '离职日期' => 0, '记录结束日期' => 0];
        foreach ($pending as $row) {
            if ($row['rawStart'] !== null && $row['normStart'] === null) {
                $stats['记录开始日期']++;
            }
            if (($row['员工状态'] ?? '') === '离职') {
                if ($row['rawResign'] !== null && $row['normResign'] === null) {
                    $stats['离职日期']++;
                }
                if ($row['rawEnd'] !== null && $row['normEnd'] === null) {
                    $stats['记录结束日期']++;
                }
            }
        }
        return $stats;
    }

    /** 状态失真行数（fixStage 预检） */
    private function countStageDiverged(): int
    {
        $row = $this->query("
            SELECT COUNT(*) AS c
            FROM ee_application a
            JOIN ee_employment em ON em.候选人编码 = a.候选人编码
            WHERE a.当前阶段 = '入职' AND em.员工状态 = '离职'")->getRowArray();
        return (int) ($row['c'] ?? 0);
    }

    /** B 类实际回填行数（执行后统计：源表有效行中人员编码已非空的行数） */
    private function countBackfilled(): int
    {
        $row = $this->query("
            SELECT COUNT(*) AS c FROM ee_onjob
            WHERE 有效标识 = '1' AND 删除标识 = '0'
              AND 人员编码 <> ''")->getRowArray();
        return (int) ($row['c'] ?? 0);
    }

    // ------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------

    private function maskId(string $id): string
    {
        return mb_strlen($id) > 10
            ? mb_substr($id, 0, 6) . '********' . mb_substr($id, -4)
            : '****';
    }

    /** 直读（绕开 Mcommon::select 请求级缓存） */
    private function query(string $sql)
    {
        $result = $this->model->getDb()->query($sql);
        if ($result === false) {
            throw new RuntimeException('查询失败: ' . substr($sql, 0, 200));
        }
        return $result;
    }
}
