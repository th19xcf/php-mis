<?php

namespace App\Services\Workbench;

use App\Exceptions\ValidationException;
use App\Libraries\MetadataCache;
use App\Models\Mcommon;
use App\Services\Workbench\ContextService;
use App\Traits\ChartColumnConfigTrait;
use Config\Services;

/**
 * 图形钻取服务类
 *
 * 参考旧版 Frame.php::chart_drill 的设计：
 *  - 根据图表点击的 SID 解析出"图形模块^图形编号"
 *  - 根据 def_chart_drill_config 获取钻取选项及下一级钻取图形配置
 *  - 通过 session 累加各级钻取条件（chart_drill_cond_str），实现多级钻取叠加
 *  - 通过 session 累加钻取标题（chart_drill_title_str），用于图表副标题显示
 */
class ChartDrillService
{
    use ChartColumnConfigTrait;

    private Mcommon $model;
    private ContextService $contextService;
    private MetadataCache $metadataCache;

    public function __construct()
    {
        $this->model = new Mcommon();
        // 用于兜底获取 queryTable / deptNameStr：旧版走 Frame::init 会预置 session；
        // 新版 Workbench 不经过 init，因此需要按需重新构建上下文
        $this->contextService = new ContextService();
        $this->metadataCache = new MetadataCache();
    }

    /**
     * 触发图形钻取
     *
     * 请求参数结构：
     *   [
     *     { '钻取级别': 0|1|2|... },   // 0=初始，1=第一级钻取，依次累加
     *     { '钻取选项': 'option^chart_module^drill_module' },
     *     { 'SID': '图形模块^图形编号', ...其他数据点字段 }
     *   ]
     *
     * @param string $functionCode 功能编码（用于定位 menu_id）
     * @param array  $payload      前端请求数据
     * @return array
     */
    public function performChartDrill(string $functionCode, array $payload): array
    {
        $session = Services::session();
        // 兼容多级菜单嵌套场景；若无 menu_id 则使用功能编码
        $menuId = $session->get('menu_id') ?: $functionCode;

        $drillLevel  = isset($payload[0]['钻取级别']) ? (int) $payload[0]['钻取级别'] : 0;
        $drillOption = isset($payload[1]['钻取选项']) ? (string) $payload[1]['钻取选项'] : '';
        $drillData   = $payload[2] ?? [];

        if ($drillOption === '') {
            throw new ValidationException('钻取选项不能为空');
        }

        // 解析钻取选项：option^chart_module^drill_module
        //   - $option  = 钻取选项
        //   - $chartId = def_chart_drill_config.图形模块（钻取目标图形的图形模块）
        //   - $drillId = def_chart_drill_config.钻取模块（来源图形的钻取模块标识）
        $parts      = explode('^', $drillOption);
        $option     = $parts[0] ?? '';
        $chartId    = $parts[1] ?? '';
        $drillId    = $parts[2] ?? '';

        if ($option === '' || $chartId === '' || $drillId === '') {
            throw new ValidationException('钻取选项格式错误：应为 option^chartModule^drillModule');
        }

        // 1. 取出当前钻取配置（用户点击的图形对应的下一级钻取定义）
        $drillConfigSql = sprintf(
            'select 钻取模块, 钻取选项, 钻取字段, 钻取条件, 图形模块
             from def_chart_drill_config
             where 顺序>0
               and 钻取选项=%s
               and 图形模块=%s
               and 钻取模块=%s',
            $this->model->quote($option),
            $this->model->quote($chartId),
            $this->model->quote($drillId)
        );

        $drillConfigRows = $this->model->select($drillConfigSql)->getResult() ?? [];

        // 2. 构造钻取参数串
        //    老版 Frame.php 行为：保留 dataItem.钻取参数 原值，再按 钻取字段 追加 字段^值
        //    即使 钻取字段 为空，也不丢弃 dataItem.钻取参数 中已有的 key^value
        $drillParam = (string) ($drillData['钻取参数'] ?? '');

        foreach ($drillConfigRows as $row) {
            $drillFields = trim((string) $row->钻取字段);
            if ($drillFields === '') {
                continue;
            }
            $fields = explode(';', $drillFields);
            foreach ($fields as $idx => $field) {
                $field = trim($field);
                if ($field === '') {
                    continue;
                }
                $value = isset($drillData[$field]) ? (string) $drillData[$field] : '';
                $segment = sprintf('%s^%s', $field, $this->escapeParam($value));
                $drillParam = $drillParam === '' ? $segment : ($drillParam . ';' . $segment);
            }
        }

        // 3. 钻取目标图形 = 钻取配置中 图形模块 列指向的 chart
        //    老版直接以 $chart_id = optionValue[1] 作为 钻取目标 加载（Frame.php L3401）
        //    把 $menuId 透传下去，供 SP 占位符 $查询表名 / $[部门全称赋权] 替换
        $drillTargetCharts = $this->getChartData($menuId, $chartId, '', $drillParam);

        // 4. 处理多级钻取：把本次钻取条件叠加到 session
        //    key 沿用老版约定：{menuId}^{chartId}-chart_drill_cond_str
        $chartDrillCondStr  = $session->get(sprintf('%s^%s-chart_drill_cond_str', $menuId, $chartId)) ?: '';
        $chartDrillTitleStr = $session->get(sprintf('%s^%s-chart_drill_title_str', $menuId, $chartId)) ?: '';

        if ($drillParam !== '') {
            $chartDrillCondStr = $chartDrillCondStr === ''
                ? $drillParam
                : ($chartDrillCondStr . ';' . $drillParam);
        }

        // 副标题：取所有 钻取字段 对应的 dataItem 值（老版语义）
        $currentTitle = $this->extractTitleFromData($drillData, $drillConfigRows);
        if ($currentTitle !== '') {
            $chartDrillTitleStr = $chartDrillTitleStr === ''
                ? $currentTitle
                : ($chartDrillTitleStr . ',' . $currentTitle);
        }

        $session->set(sprintf('%s^%s-chart_drill_cond_str', $menuId, $chartId), $chartDrillCondStr);
        $session->set(sprintf('%s^%s-chart_drill_title_str', $menuId, $chartId), $chartDrillTitleStr);

        // 5. 累加到 session 的钻取图形数组，供"返回初始图形"以外的状态使用
        $chartDrillArr = $session->get($menuId . '-chart_drill_arr') ?: [];
        $chartDrillArr[] = $drillTargetCharts;
        $session->set($menuId . '-chart_drill_arr', $chartDrillArr);

        return $drillTargetCharts;
    }

    /**
     * 获取钻取后的图表数据（按图形编号分桶）
     *
     * @param string $menuId        菜单标识
     * @param string $chartModule   图形模块
     * @param string $chartCode     图形编号（空表示取全部）
     * @param string $drillParam    钻取参数串（字段1^值1;字段2^值2）
     * @return array
     */
    public function getChartData(string $menuId, string $chartModule, string $chartCode, string $drillParam = ''): array
    {
        $session = Services::session();

        $condStr   = $session->get(sprintf('%s^%s-chart_drill_cond_str', $menuId, $chartModule)) ?: '';
        $titleStr  = $session->get(sprintf('%s^%s-chart_drill_title_str', $menuId, $chartModule)) ?: '';

        $sql = sprintf(
            'select 图形模块, 图形编号, 图形名称, 图形类型, 取数方式,
                    查询表名, 查询字段, 属地字段, 查询条件, 汇总条件, 排序条件, 记录条数,
                    字段模块, 页面布局, 钻取模块, 条件叠加, 顺序
             from def_chart_config
             where 有效标识="1"
               and 顺序>0
               and 图形模块=%s
               %s
             order by 图形模块, 图形编号, 顺序',
            $this->model->quote($chartModule),
            $chartCode !== '' ? sprintf('and 图形编号=%s', $this->model->quote($chartCode)) : ''
        );

        $rows = $this->model->select($sql)->getResult() ?? [];
        $result = [];

        // 批量预取所有图表的字段模块列配置，避免循环内 N+1 查询。
        $fieldModules = array_map(fn($r) => (string) ($r->字段模块 ?? ''), $rows);
        $columnConfigsMap = $this->getChartColumnConfigsBatch($fieldModules);

        foreach ($rows as $row) {
            $chartItem = $this->buildChartItem($row);
            $chartItem['图形名称'] = $titleStr === ''
                ? $row->图形名称
                : sprintf('%s(%s)', $row->图形名称, $titleStr);

            // 加载图表自身的钻取选项（与初始图形数据来源一致：def_chart_drill_config）
            // 使第 1 级钻取后的图形也能继续钻取第 2 级
            $chartItem['钻取选项'] = $this->loadChartDrillOptions((string) ($row->钻取模块 ?? ''));

            try {
                $dataSql = $this->buildChartQuerySql($row, $drillParam, $condStr, $menuId);
                $dataResults = $this->model->select($dataSql);
                $chartItem['数据'] = $dataResults ? ($dataResults->getResultArray() ?? []) : [];
                $chartItem['SQL'] = $dataSql;

                // 加载 def_chart_column 列配置（坐标轴 / 图形类型），
                // split 内部通过 $new = $baseChartItem 浅拷贝继承给每个分桶，
                // 使前端可以按字段渲染折线 / 柱 / 饼等不同图形
                $this->fillChartColumnConfigs($chartItem, $row, $columnConfigsMap);

                // 拆分多图形数据（splitChartDataByCode 内部会按"图形编号"分桶，
                // 并用每桶首行的"图形名称"列覆盖 chartItem['图形名称']，
                // 使不同图形编号对应不同的"图形名称"）
                $result = array_merge($result, $this->splitChartDataByCode($chartItem, $chartItem['数据']));
            } catch (\Throwable $e) {
                $chartItem['数据'] = [];
                $chartItem['错误'] = $e->getMessage();
                $chartItem['SQL'] = '';
                $this->fillChartColumnConfigs($chartItem, $row, $columnConfigsMap);
                $result[] = $chartItem;
            }
        }

        return $result;
    }

    /**
     * 从 SP 查询结果中提取"图形名称"
     *
     * SP 模板可在结果集中通过"图形名称"列自定义图表显示名称
     * （例如钻取后 sp_公司_预算_月完成_财务科目_预算月份_月份() 返回带"图形名称"的结果）。
     * 取首条非空值，主体取完后丢弃。
     *
     * @param array $dataRows SP 返回的数据行
     * @return string|null 找到返回字符串，未找到返回 null
     */
    private function extractSpChartName(array $dataRows): ?string
    {
        foreach ($dataRows as $row) {
            $row = (array) $row;
            if (array_key_exists('图形名称', $row)) {
                $val = trim((string) ($row['图形名称'] ?? ''));
                if ($val !== '') {
                    return $val;
                }
            }
        }
        return null;
    }

    /**
     * 构建图形基础信息
     */
    private function buildChartItem(object $row): array
    {
        return [
            '图形模块' => (string) $row->图形模块,
            '图形编号' => (string) $row->图形编号,
            '图形名称' => (string) $row->图形名称,
            '图形类型' => (string) $row->图形类型,
            '取数方式' => (string) $row->取数方式,
            '页面布局' => (string) $row->页面布局,
            '字段模块' => (string) $row->字段模块,
            '钻取模块' => (string) $row->钻取模块,
            '数据' => []
        ];
    }

    /**
     * 构建图形数据 SQL
     *
     * @param object $row        图表配置行
     * @param string $drillParam 钻取参数字符串
     * @param string $condStr    多级钻取叠加条件
     * @param string $menuId     菜单标识（用于 $查询表名 / $[部门全称赋权] 占位符）
     */
    private function buildChartQuerySql(object $row, string $drillParam, string $condStr = '', string $menuId = ''): string
    {
        // 存储过程：直接 call <sp>(...)，$fieldName 用 drillParam 替换
        if (isset($row->取数方式) && (string) $row->取数方式 === '存储过程') {
            $session = Services::session();
            $spCall  = (string) $row->查询表名;

            // 老版约定：$查询表名 / $[部门全称赋权] 占位符
            // 1) 优先从 session 读取（兼容旧版 Frame::init 链路）
            // 2) session 缺失时，按 menuId 重建 context 兜底（新版 Workbench 链路）
            $queryTable  = $session->get($menuId . '-query_table')  ?: '';
            $deptNameStr = $session->get($menuId . '-dept_name_str') ?: '';

            if (($queryTable === '' || $deptNameStr === '') && $menuId !== '') {
                [$queryTable, $deptNameStr] = $this->resolveStoredProcedureContext(
                    $menuId,
                    $queryTable,
                    $deptNameStr
                );
                // 回填 session，供后续钻取/重入复用
                if ($queryTable !== '') {
                    $session->set($menuId . '-query_table', $queryTable);
                }
                if ($deptNameStr !== '') {
                    $session->set($menuId . '-dept_name_str', $deptNameStr);
                }
            }

            $spCall = str_replace('$查询表名', (string) $queryTable, $spCall);
            $spCall = str_replace('$[部门全称赋权]', sprintf('\'[%s]\'', $deptNameStr), $spCall);

            if (strpos(ltrim($spCall), 'call ') !== 0) {
                $spCall = 'call ' . $spCall;
            }

            // 用 drillParam 替换 $fieldName 占位符（老版 Frame.php L3562-L3564 行为）
            // 注意：$fieldName 周围的引号是 SP 模板自带的，替换值不能再加引号
            if ($drillParam !== '') {
                foreach (explode(';', $drillParam) as $item) {
                    $kv = explode('^', $item);
                    if (count($kv) >= 2) {
                        $spCall = str_replace(
                            sprintf('$%s', trim($kv[0])),
                            trim($kv[1]),
                            $spCall
                        );
                    }
                }
            }
            return $spCall;
        }

        $whereParts = [];

        // 1. 钻取参数生成 where
        $drillParam = trim($drillParam);
        if ($drillParam !== '') {
            $paramItems = explode(';', $drillParam);
            foreach ($paramItems as $item) {
                $kv = explode('^', $item);
                if (count($kv) >= 2) {
                    $fld   = trim($kv[0]);
                    $val   = trim($kv[1]);
                    if ($fld !== '' && $val !== '') {
                        $whereParts[] = sprintf('%s=%s', $fld, $this->model->quote($val));
                    }
                }
            }
        }

        // 2. 钻取条件：替换占位符
        $baseCond = trim((string) $row->查询条件);
        if ($baseCond !== '') {
            $baseCond = str_replace('`', '"', $baseCond);
            // 替换 $field 占位符
            if ($drillParam !== '') {
                foreach (explode(';', $drillParam) as $item) {
                    $kv = explode('^', $item);
                    if (count($kv) >= 2) {
                        $baseCond = str_replace(
                            sprintf('$%s', trim($kv[0])),
                            $this->model->quote(trim($kv[1])),
                            $baseCond
                        );
                    }
                }
            }
            $whereParts[] = $baseCond;
        }

        // 3. 多级钻取条件叠加
        if ($condStr !== '' && (string) $row->条件叠加 === '1') {
            $whereParts[] = $condStr;
        }

        $where = $whereParts === [] ? '1=1' : implode(' and ', $whereParts);

        $sql = sprintf(
            'select %s, %s as SID from %s where %s',
            (string) $row->查询字段,
            $this->model->quote(sprintf('%s^%s', $row->图形模块, $row->图形编号)),
            (string) $row->查询表名,
            $where
        );

        if (!empty($row->汇总条件)) {
            $sql .= ' group by ' . $row->汇总条件;
        }
        if (!empty($row->排序条件)) {
            $sql .= ' order by ' . $row->排序条件;
        }
        if (!empty($row->记录条数) && is_numeric($row->记录条数)) {
            $sql .= ' limit ' . (int) $row->记录条数;
        }

        return $sql;
    }

    /**
     * 将数据按"图形编号"字段拆分到独立图表
     *
     * - 按"图形编号"列分桶；缺省按 SID 解析
     * - SP 模板可在结果集中通过"图形名称"列自定义每桶的图表名称，
     *   拆桶时取桶内首行非空的"图形名称"作为该桶 chart item 的"图形名称"
     * - 保留 baseChartItem['图形名称'] 中已拼装的 "(titleStr)" 副标题后缀
     */
    private function splitChartDataByCode(array $baseChartItem, array $dataResults): array
    {
        if (empty($dataResults)) {
            return [$baseChartItem];
        }

        $codes = [];
        foreach ($dataResults as $item) {
            $item = (array) $item;
            if (isset($item['图形编号'])) {
                $codes[(string) $item['图形编号']] = true;
            } elseif (isset($item['SID'])) {
                $parts = explode('^', (string) $item['SID']);
                if (isset($parts[1])) {
                    $codes[$parts[1]] = true;
                }
            }
        }

        if (empty($codes)) {
            // 不分桶：用首行的"图形名称"覆盖主体，保留可能的副标题后缀
            $spName = $this->extractSpChartName($dataResults);
            if ($spName !== null) {
                $baseChartItem['图形名称'] = $this->mergeSpNameWithSubtitle($baseChartItem['图形名称'], $spName);
            }
            $baseChartItem['数据'] = $dataResults;
            return [$baseChartItem];
        }

        $result = [];
        foreach (array_keys($codes) as $code) {
            $new = $baseChartItem;
            $new['图形编号'] = (string) $code;
            $new['数据'] = [];
            $bucketHasRow = false;
            foreach ($dataResults as $item) {
                $item = (array) $item;
                $codeVal = isset($item['图形编号']) ? (string) $item['图形编号'] : (explode('^', (string) ($item['SID'] ?? ''))[1] ?? '');
                if ($codeVal === (string) $code) {
                    // 桶内首行命中：用其"图形名称"覆盖 chart item 名称主体，
                    // 使不同"图形编号"对应不同的"图形名称"
                    if (!$bucketHasRow) {
                        $spName = isset($item['图形名称']) ? trim((string) $item['图形名称']) : '';
                        if ($spName !== '') {
                            $new['图形名称'] = $this->mergeSpNameWithSubtitle($new['图形名称'], $spName);
                        }
                        $bucketHasRow = true;
                    }
                    $new['数据'][] = $item;
                }
            }
            $result[] = $new;
        }

        return $result;
    }

    /**
     * 用 SP 返回的"图形名称"作为主体，保留 baseName 中已存在的 "(titleStr)" 副标题后缀
     */
    private function mergeSpNameWithSubtitle(mixed $baseName, string $spName): string
    {
        $current = (string) $baseName;
        $parenPos = mb_strpos($current, '(');
        return $parenPos === false
            ? $spName
            : $spName . mb_substr($current, $parenPos);
    }

    /**
     * 从 ContextService 重建 queryTable / deptNameStr，兜底 session 缺失场景
     *
     * - queryTable：来自 def_query_config.查询表名（功能编码 → 查询模块 → 查询表名）
     * - deptNameStr：来自用户/角色 部门全称赋权，老版格式 `"a","b"`
     *
     * @param string $menuId             菜单/功能编码
     * @param string $existingQueryTable session 中已有的 queryTable
     * @param string $existingDeptNameStr session 中已有的 deptNameStr
     * @return array{0:string,1:string}  [queryTable, deptNameStr]
     */
    private function resolveStoredProcedureContext(string $menuId, string $existingQueryTable, string $existingDeptNameStr): array
    {
        try {
            [$context] = $this->contextService->buildWorkbenchContext($menuId);
        } catch (\Throwable $e) {
            log_message('error', sprintf(
                '[ChartDrillService] 重建 menuId=%s 的 context 失败: %s',
                $menuId,
                $e->getMessage()
            ));
            return [$existingQueryTable, $existingDeptNameStr];
        }

        $queryTable = $existingQueryTable !== ''
            ? $existingQueryTable
            : (string) ($context['queryTable'] ?? '');

        if ($existingDeptNameStr === '') {
            $deptNameStr = $this->formatDeptNameStr((string) ($context['user']['deptNameAuth'] ?? ''));
        } else {
            $deptNameStr = $existingDeptNameStr;
        }

        log_message('debug', sprintf(
            '[ChartDrillService] 兜底加载 context 成功: menuId=%s, queryTable=%s, deptNameStr=%s',
            $menuId,
            $queryTable,
            $deptNameStr
        ));

        return [$queryTable, $deptNameStr];
    }

    /**
     * 把新版的 deptNameAuth（逗号分隔）转成老版 dept_name_str 格式：`"a","b"`
     *  - 空 / "不限" / "无" → 空字符串
     */
    private function formatDeptNameStr(string $deptNameAuth): string
    {
        $deptNameAuth = trim($deptNameAuth);
        if ($deptNameAuth === '' || $deptNameAuth === '不限') {
            return '';
        }

        $parts = array_values(array_filter(
            array_map('trim', explode(',', $deptNameAuth)),
            static fn(string $p): bool => $p !== ''
        ));

        if ($parts === []) {
            return '';
        }

        return implode(',', array_map(static fn(string $p): string => sprintf('"%s"', $p), $parts));
    }

    /**
     * 从数据点中提取副标题文本
     */
    private function extractTitleFromData(array $data, array $drillRows): string
    {
        $title = '';
        foreach ($drillRows as $row) {
            $fields = explode(';', (string) $row->钻取字段);
            foreach ($fields as $field) {
                $field = trim($field);
                if ($field !== '' && isset($data[$field]) && $data[$field] !== '') {
                    $title = $title === '' ? (string) $data[$field] : ($title . ',' . $data[$field]);
                }
            }
        }
        return $title;
    }

    /**
     * 转义钻取参数
     */
    private function escapeParam(string $value): string
    {
        return str_replace(['^', ';'], ['', ','], $value);
    }

    /**
     * 加载图表自身的钻取选项（参考 ChartService.loadChartDrillOptions）
     *
     * 数据来源：def_chart_drill_config（按 钻取模块 匹配）
     * 与初始图形数据来源一致，使第 1 级钻取后的图形也能继续钻取第 2 级。
     *
     * @param string $drillModule 图表的钻取模块（def_chart_config.钻取模块）
     * @return array
     */
    private function loadChartDrillOptions(string $drillModule): array
    {
        if ($drillModule === '') {
            return [];
        }

        $configs = $this->metadataCache->getChartDrillConfig($drillModule);
        $options = [];

        foreach ($configs as $row) {
            $option = (string) ($row['钻取选项'] ?? '');
            $chartModule = (string) ($row['图形模块'] ?? '');
            $module = (string) ($row['钻取模块'] ?? '');
            if ($option === '') {
                continue;
            }
            $options[] = [
                'label' => $option,
                'value' => $option . '^' . $chartModule . '^' . $module,
                'functionCode' => $module,
                'module' => $module,
                'chartModule' => $chartModule,
                'drillOption' => $option,
                'drillFields' => (string) ($row['钻取字段'] ?? ''),
                'drillCondition' => (string) ($row['钻取条件'] ?? '')
            ];
        }

        return $options;
    }
}
