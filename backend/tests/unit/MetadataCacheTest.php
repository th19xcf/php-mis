<?php

namespace Tests\Unit;

use App\Libraries\ConfigTableFingerprint;
use App\Libraries\MetadataCache;
use App\Models\Mcommon;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\FakeCache;
use Tests\Support\FakeMcommon;
use Tests\Support\FakeResult;

/**
 * MetadataCache 特征测试
 *
 * 焦点：SQL 瞬时失败（select 返回 false）时的缓存写入行为。
 * - getViewFunctionColumns 曾把空结果连同有效指纹缓存 3600s（已修复，此处回归锁定）
 * - getChartDrillConfig / getPopupColumnMap 等同类方法的"失败不缓存"现状守卫
 */
class MetadataCacheTest extends CIUnitTestCase
{
    private const VIEW_FN_TABLES = [
        'def_function', 'def_query_config', 'def_query_column',
        'def_grid_style', 'def_drill_config', 'def_import_config',
    ];

    /**
     * 构造注入替身的 MetadataCache（model / cache / fingerprintService 均为 private，反射替换）
     */
    private function makeCache(Mcommon $model, FakeCache $cache): MetadataCache
    {
        $mc = new MetadataCache();

        foreach (['model' => $model, 'cache' => $cache, 'fingerprintService' => new StubFingerprint()] as $prop => $value) {
            $p = new \ReflectionProperty(MetadataCache::class, $prop);
            $p->setAccessible(true);
            $p->setValue($mc, $value);
        }

        return $mc;
    }

    // ---- getViewFunctionColumns：SQL 失败不缓存（回归锁定）----

    public function testViewFunctionColumnsSqlFailureReturnsEmptyWithoutCaching(): void
    {
        $model = new FakeMcommon();
        $model->forceSelectReturn = false;
        $fakeCache = new FakeCache();
        $mc = $this->makeCache($model, $fakeCache);

        $rows = $mc->getViewFunctionColumns('9999');

        $this->assertSame([], $rows);
        // 核心：SQL 失败时不得写入任何缓存（含 view_function 数据键与反向索引键）
        $this->assertSame([], $fakeCache->saves, 'SQL 失败时不得写入缓存');
    }

    public function testViewFunctionColumnsSqlFailureNotCachedEvenForKnownCode(): void
    {
        // 已知功能编码同样不得在失败时留下污染缓存
        $model = new FakeMcommon();
        $model->forceSelectReturn = false;
        $fakeCache = new FakeCache();
        $mc = $this->makeCache($model, $fakeCache);

        $mc->getViewFunctionColumns('50012');

        $this->assertArrayNotHasKey(
            'metadata_view_function_' . md5('50012'),
            $fakeCache->store
        );
    }

    // ---- getViewFunctionColumns：正常路径缓存结构 ----

    public function testViewFunctionColumnsSuccessWritesCacheWithFingerprints(): void
    {
        $rows = [
            ['列名' => '姓名', '字段名' => 'name', '列顺序' => 1],
            ['列名' => '金额', '字段名' => 'amount', '列顺序' => 2],
        ];
        $model = new FakeMcommon();
        $model->forceSelectReturn = new FakeResult($rows);
        $fakeCache = new FakeCache();
        $mc = $this->makeCache($model, $fakeCache);

        $result = $mc->getViewFunctionColumns('50012');

        $this->assertSame($rows, $result);

        $cacheKey = 'metadata_view_function_' . md5('50012');
        $this->assertArrayHasKey($cacheKey, $fakeCache->store);

        $saved = $fakeCache->store[$cacheKey];
        $this->assertSame($rows, $saved['__data']);
        $this->assertSame(array_fill_keys(self::VIEW_FN_TABLES, 'stub-fp'), $saved['__fps']);
        $this->assertSame(self::VIEW_FN_TABLES, $saved['__fpTables']);

        // 数据键 + 6 张依赖表的反向索引键
        $savedKeys = array_column($fakeCache->saves, 'key');
        $this->assertContains($cacheKey, $savedKeys);
        foreach (self::VIEW_FN_TABLES as $table) {
            $this->assertContains('metadata_index_' . $table, $savedKeys);
        }
    }

    public function testViewFunctionColumnsEmptyButSuccessfulResultStillCached(): void
    {
        // 查询成功但确实无列（如功能编码配置缺失）→ 空数组属于真实结果，允许缓存
        $model = new FakeMcommon();
        $model->forceSelectReturn = new FakeResult([]);
        $fakeCache = new FakeCache();
        $mc = $this->makeCache($model, $fakeCache);

        $rows = $mc->getViewFunctionColumns('9999');

        $this->assertSame([], $rows);
        $this->assertArrayHasKey('metadata_view_function_' . md5('9999'), $fakeCache->store);
    }

    // ---- getViewFunctionColumns：缓存命中 ----

    public function testViewFunctionColumnsCacheHitSkipsDb(): void
    {
        $rows = [['列名' => '姓名', '字段名' => 'name', '列顺序' => 1]];
        $fakeCache = new FakeCache();
        $fakeCache->store['metadata_view_function_' . md5('50012')] = [
            '__data' => $rows,
            '__fps' => array_fill_keys(self::VIEW_FN_TABLES, 'stub-fp'),
        ];
        $model = new FakeMcommon();
        $mc = $this->makeCache($model, $fakeCache);

        $result = $mc->getViewFunctionColumns('50012');

        $this->assertSame($rows, $result);
        $this->assertSame([], $model->selectSqlLog, '缓存命中时不得触达数据库');
    }

    // ---- 同类方法"失败不缓存"现状守卫 ----

    public function testChartDrillConfigSqlFailureReturnsEmptyWithoutCaching(): void
    {
        $model = new FakeMcommon();
        $model->forceSelectReturn = false;
        $fakeCache = new FakeCache();
        $mc = $this->makeCache($model, $fakeCache);

        $config = $mc->getChartDrillConfig('公司_财务_钻取');

        $this->assertSame([], $config);
        $this->assertSame([], $fakeCache->saves);
    }

    public function testPopupColumnMapSqlFailureReturnsEmptyWithoutCaching(): void
    {
        $model = new FakeMcommon();
        $model->forceSelectReturn = false;
        $fakeCache = new FakeCache();
        $mc = $this->makeCache($model, $fakeCache);

        $map = $mc->getPopupColumnMap();

        $this->assertSame([], $map);
        $this->assertSame([], $fakeCache->saves);
    }
}

/**
 * ConfigTableFingerprint 桩：固定指纹值，不触达数据库
 */
class StubFingerprint extends ConfigTableFingerprint
{
    public function getFingerprints(array $tableNames): array
    {
        return array_fill_keys($tableNames, 'stub-fp');
    }

    public function isValidMultiple(array $tableNames, array $cachedFingerprints): bool
    {
        return $cachedFingerprints === array_fill_keys($tableNames, 'stub-fp');
    }
}
