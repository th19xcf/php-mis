<?php

namespace App\Commands;

use App\Models\Mcommon;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * 下游三表重复候选人编码审计命令
 *
 * 用法：
 *   php spark dup:audit                # 汇总 + 每组前3条样本，输出到 CLI
 *   php spark dup:audit --format json  # 写 JSON + CSV 到 writable/logs/dup_audit_*
 *   php spark dup:audit --full         # 每组全量输出（默认每组前10条，避免输出爆炸）
 */
class DuplicateCodeAudit extends BaseCommand
{
    protected $group       = 'Person';
    protected $name        = 'dup:audit';
    protected $description = '下游三表(ee_interview/ee_train/ee_onjob)重复候选人编码审计';
    protected $usage       = 'php spark dup:audit [--format json|text] [--full]';
    protected $arguments   = [];
    protected $options     = [
        '--format' => '输出格式：text(默认) 或 json(同时写 JSON+CSV 文件)',
        '--full'   => '每组输出全部行（默认每组最多 10 条摘要避免刷屏）',
    ];

    private Mcommon $model;
    private const PER_GROUP_LIMIT = 10;

    private const TABLE_KEY_COLS = [
        'ee_interview' => [
            'GUID', '候选人编码', '人员编码', '姓名', '身份证号', '手机号码',
            '面试业务', '面试岗位', '一次面试日期', '一次面试结果',
            '有效标识', '删除标识', '操作来源', '操作人员', '操作时间',
            '开始操作时间', '结束操作时间',
        ],
        'ee_train' => [
            'GUID', '候选人编码', '人员编码', '姓名', '身份证号', '手机号码',
            '培训业务', '培训状态', '培训开始日期', '培训完成日期', '培训离开日期',
            '有效标识', '删除标识', '操作来源', '操作人员', '操作时间',
            '开始操作时间', '结束操作时间',
        ],
        'ee_onjob' => [
            'GUID', '候选人编码', '人员编码', '姓名', '身份证号', '手机号码',
            '岗位名称', '员工状态', '记录开始日期', '记录结束日期', '离职日期',
            '有效标识', '删除标识', '操作来源', '操作人员', '操作时间',
            '开始操作时间', '结束操作时间',
        ],
    ];

    public function run(array $params)
    {
        $this->model = new Mcommon();
        $format      = strtolower((string) (CLI::getOption('format') ?? 'text'));
        $full        = CLI::getOption('full') !== null;

        $tables = ['ee_interview', 'ee_train', 'ee_onjob'];
        $report = [
            'generatedAt' => date('Y-m-d H:i:s'),
            'tables'      => [],
            'grandTotal'  => ['groups' => 0, 'rowsInvolved' => 0, 'extras' => 0],
        ];

        CLI::write('下游三表重复候选人编码审计', 'yellow');
        CLI::newLine();

        foreach ($tables as $table) {
            $t0  = microtime(true);
            $res = $this->auditTable($table, $full);
            $res['queryMs'] = (int) round((microtime(true) - $t0) * 1000);
            $report['tables'][$table] = $res;

            $report['grandTotal']['groups']       += $res['groupCount'];
            $report['grandTotal']['rowsInvolved'] += $res['rowsInvolved'];
            $report['grandTotal']['extras']       += $res['rowsInvolved'] - $res['groupCount'];

            $this->printTableSummary($table, $res, $full);
        }

        CLI::write('==================== 总计 ====================', 'yellow');
        CLI::write(sprintf(
            '三组表合计：重复组 %d 个 · 涉及行 %d 条 · 冗余行 %d 条（= 保留一条后需要失效的数量）',
            $report['grandTotal']['groups'],
            $report['grandTotal']['rowsInvolved'],
            $report['grandTotal']['extras']
        ), 'light_green');

        $this->printRetentionRules();

        if ($format === 'json') {
            $dir = WRITEPATH . 'logs';
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $base = $dir . '/dup_audit_' . date('Ymd_His');
            file_put_contents($base . '.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            CLI::newLine();
            CLI::write('JSON 报告已写入: ' . $base . '.json', 'light_green');
            foreach ($tables as $table) {
                $csv = $this->buildCsv($table, $report['tables'][$table]);
                file_put_contents($base . '_' . $table . '.csv', "\xEF\xBB\xBF" . $csv);
                CLI::write($table . ' CSV 已写入: ' . $base . '_' . $table . '.csv', 'light_green');
            }
        }
        return EXIT_SUCCESS;
    }

    private function auditTable(string $table, bool $full): array
    {
        $cols = self::TABLE_KEY_COLS[$table];
        $db   = $this->model->getDb();

        $statSql = "SELECT COUNT(*) AS groupCount, SUM(cnt) AS rowsInvolved,
                           MAX(cnt) AS maxDup, SUM(cnt-1) AS extras
                    FROM (
                        SELECT COUNT(*) AS cnt
                        FROM {$table}
                        WHERE 有效标识='1' AND 删除标识='0' AND IFNULL(候选人编码,'') <> ''
                        GROUP BY 候选人编码
                        HAVING cnt > 1
                    ) t";
        $stat = $this->model->select($statSql)->getRowArray();
        $groupCount   = (int) ($stat['groupCount'] ?? 0);
        $rowsInvolved = (int) ($stat['rowsInvolved'] ?? 0);
        $maxDup       = (int) ($stat['maxDup'] ?? 0);

        if ($groupCount === 0) {
            return ['groupCount' => 0, 'rowsInvolved' => 0, 'maxDup' => 0, 'groups' => []];
        }

        $groupCodes = $this->model->select(
            "SELECT 候选人编码 AS code, COUNT(*) AS cnt
             FROM {$table}
             WHERE 有效标识='1' AND 删除标识='0' AND IFNULL(候选人编码,'') <> ''
             GROUP BY 候选人编码 HAVING cnt > 1
             ORDER BY cnt DESC, code ASC"
        )->getResultArray();

        $quoted = implode(',', array_map(fn($r) => $db->escape($r['code']), $groupCodes));
        $colList = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
        $rows = $this->model->select(
            "SELECT {$colList} FROM {$table}
             WHERE 候选人编码 IN ({$quoted}) AND 有效标识='1' AND 删除标识='0'
             ORDER BY 候选人编码 ASC, 开始操作时间 ASC, GUID ASC"
        )->getResultArray();

        $indexed = [];
        foreach ($groupCodes as $g) {
            $indexed[$g['code']] = ['candidateCode' => $g['code'], 'rowCount' => (int)$g['cnt'], 'rows' => []];
        }
        foreach ($rows as $r) {
            $indexed[$r['候选人编码']]['rows'][] = $r;
        }
        foreach ($indexed as &$grp) {
            $this->annotateRetention($grp);
        }
        unset($grp);

        return [
            'groupCount'   => $groupCount,
            'rowsInvolved' => $rowsInvolved,
            'maxDup'       => $maxDup,
            'groups'       => array_values($indexed),
        ];
    }

    private function annotateRetention(array &$grp): void
    {
        $bestIdx = -1;
        $best    = -PHP_INT_MAX;
        foreach ($grp['rows'] as $i => &$r) {
            $score = 0;
            if (!empty($r['身份证号'])) $score += 50;
            if (!empty($r['手机号码'])) $score += 40;
            if (!empty($r['人员编码'])) $score += 30;
            foreach (['一次面试结果', '培训状态', '员工状态'] as $k) {
                if (isset($r[$k]) && !in_array((string)$r[$k], ['', '在培', '在职'], true)) $score += 10;
            }
            $score += 3;
            if (!empty($r['结束操作时间'])) $score -= 5;
            $score -= ((int)($r['GUID'] ?? 0)) * 0.00001;
            $r['_retentionScore'] = round($score, 6);
            if ($score > $best) {
                $best    = $score;
                $bestIdx = $i;
            }
        }
        unset($r);
        foreach ($grp['rows'] as $i => &$r) {
            $r['_suggestKeep'] = ($i === $bestIdx) ? 'YES' : 'NO';
        }
        unset($r);
    }

    private function printTableSummary(string $table, array $res, bool $full): void
    {
        $limit = $full ? PHP_INT_MAX : self::PER_GROUP_LIMIT;
        CLI::write("---- {$table} ----", 'cyan');
        if ($res['groupCount'] === 0) {
            CLI::write('  无重复候选人编码 ✓', 'light_green');
            CLI::newLine();
            return;
        }
        CLI::write(sprintf(
            '  重复组=%d · 涉及行=%d · 冗余行=%d · 单码最大重复=%d（耗时 %dms）',
            $res['groupCount'], $res['rowsInvolved'],
            $res['rowsInvolved'] - $res['groupCount'],
            $res['maxDup'], $res['queryMs'] ?? 0
        ));

        $shown = 0;
        foreach ($res['groups'] as $grp) {
            if ($shown >= $limit) break;
            $shown++;
            CLI::write(sprintf(
                '  候选人编码=%s · 本码%d条（显示前%d，建议保留=_suggestKeep=YES）',
                $grp['candidateCode'], $grp['rowCount'], min(count($grp['rows']), 5)
            ));
            foreach (array_slice($grp['rows'], 0, 5) as $r) {
                $tag = $r['_suggestKeep'] === 'YES' ? 'KEEP' : 'DROP';
                $id  = mb_substr((string)($r['身份证号'] ?? ''), 0, 6);
                $ph  = mb_substr((string)($r['手机号码'] ?? ''), -4);
                $st  = $r['一次面试结果'] ?? $r['培训状态'] ?? $r['员工状态'] ?? '';
                CLI::write(sprintf(
                    '    [%s] GUID=%s · 姓名=%s · 证=%s… · 尾号=%s · 状态=%s · 开始=%s · 结束=%s · 得分=%.5f',
                    $tag, $r['GUID'], $r['姓名'] ?? '', $id, $ph, $st,
                    $r['开始操作时间'] ?? '', $r['结束操作时间'] ?? '', $r['_retentionScore']
                ));
            }
        }
        if (!$full && $res['groupCount'] > $limit) {
            CLI::write(sprintf(
                '  …… 余下 %d 组省略；加 --full 查看全部，或加 --format json 写 JSON+CSV 文件',
                $res['groupCount'] - $limit
            ), 'gray');
        }
        CLI::newLine();
    }

    private function printRetentionRules(): void
    {
        CLI::newLine();
        CLI::write('========= 建议保留行判定规则（自动打分） =========', 'yellow');
        CLI::write('  +50 身份证号非空 · +40 手机号非空 · +30 人员编码非空');
        CLI::write('  +10 业务状态(一次面试结果/培训状态/员工状态)有值且非默认');
        CLI::write('  +3 倾向越早录入 · -5 结束操作时间已存在(已结束) · 极小GUID加权');
        CLI::write('  得分最高=YES(保留)，其余=NO(建议 UPDATE 有效标识=0)');
        CLI::write('  ⚠ 此为自动规则，涉及人员状态/入职/薪资的关键表请结合业务人工复核后再失效');
    }

    private function buildCsv(string $table, array $res): string
    {
        if ($res['groupCount'] === 0) {
            return "无重复候选人编码\n";
        }
        $cols = array_merge(self::TABLE_KEY_COLS[$table], ['_retentionScore', '_suggestKeep']);
        $out = fopen('php://temp', 'r+b');
        fputcsv($out, $cols);
        foreach ($res['groups'] as $grp) {
            foreach ($grp['rows'] as $r) {
                $line = [];
                foreach (self::TABLE_KEY_COLS[$table] as $c) {
                    $line[] = $r[$c] ?? '';
                }
                $line[] = $r['_retentionScore'];
                $line[] = $r['_suggestKeep'];
                fputcsv($out, $line);
            }
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv;
    }
}
