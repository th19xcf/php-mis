<?php

namespace App\Services\Workbench;

use App\Libraries\MetadataCache;
use App\Models\Mcommon;

/**
 * 弹窗服务类
 *
 * 负责处理工作台弹窗（树形级联选择）相关业务逻辑：
 *  - getPopupConfig  查弹窗对象配置
 *  - getPopupData    一次性返回所有级别（首屏用）
 *  - getPopupLevels  返回级别元数据
 *  - getPopupLevelData  懒加载指定级别
 */
class PopupService
{
    private Mcommon $model;
    private MetadataCache $metadataCache;

    public function __construct()
    {
        $this->model = new Mcommon();
        $this->metadataCache = new MetadataCache();
    }

    /**
     * 查询弹窗对象配置（对象表名等）
     *
     * @param string $functionCode 功能编码
     * @param string $objectName 对象名称
     * @return array|null ['对象' => ..., '对象名称' => ..., '对象表名' => ...] | null
     */
    public function getPopupConfig(string $functionCode, string $objectName): ?array
    {
        $allColumns = $this->metadataCache->getViewFunctionColumns($functionCode);

        foreach ($allColumns as $col) {
            if (($col['赋值类型'] ?? '') === '弹窗' && ($col['对象'] ?? '') === $objectName) {
                return [
                    '对象' => $col['对象'] ?? '',
                    '对象名称' => $col['对象名称'] ?? '',
                    '对象表名' => $col['对象表名'] ?? '',
                ];
            }
        }

        return $this->metadataCache->getPopupConfigByObject($objectName);
    }

    /**
     * 获取弹窗数据（一次性返回所有级别）
     *
     * @return array ['popupGrid' => array, 'popupObj' => array, 'maxLevel' => int]
     */
    public function getPopupData(string $functionCode, string $objectName): array
    {
        $config = $this->getPopupConfig($functionCode, $objectName);
        if ($config === null) {
            return [
                'popupGrid' => [],
                'popupObj'  => [],
                'maxLevel'  => 1,
            ];
        }

        $objSql = sprintf(
            'select 对象名称, 本级编码, 本级名称, 本级全称, 本级级别名称, 本级级别,
                上级编码, 上级名称, 上级全称, 上级级别名称, 最大级别, 本级初始值
            from %s
            order by 对象名称, 本级级别, 本级全称',
            $config['对象表名']
        );

        $objResult = $this->model->select($objSql);
        if ($objResult === false) {
            return [
                'popupGrid' => [],
                'popupObj'  => [],
                'maxLevel'  => 1,
            ];
        }

        $objRows = $objResult->getResultArray();

        $popupGrid = [];
        $popupObj = [];

        foreach ($objRows as $objRow) {
            $levelName = $objRow['本级级别名称'];
            $parentName = $objRow['上级名称'];

            if (!isset($popupObj[$levelName])) {
                $popupObj[$levelName] = [];
                $popupObj[$levelName]['本级级别'] = $objRow['本级级别'];
                $popupObj[$levelName]['本级初始值'] = $objRow['本级初始值'];
                $popupObj[$levelName]['上级级别名称'] = $objRow['上级级别名称'];

                $popupGrid[] = [
                    '表项' => $levelName,
                    '级别' => $objRow['本级级别'],
                    '取值' => $objRow['本级初始值'],
                ];
            }

            if (!isset($popupObj[$levelName][$parentName])) {
                $popupObj[$levelName][$parentName] = [];
            }
            $popupObj[$levelName][$parentName][] = $objRow['本级名称'];
        }

        return [
            'popupGrid' => $popupGrid,
            'popupObj'  => $popupObj,
            'maxLevel'  => (int) ($objRows[0]['最大级别'] ?? 1),
        ];
    }

    /**
     * 获取弹窗级联级别配置
     *
     * @return array ['levels' => array, 'maxLevel' => int]
     */
    public function getPopupLevels(string $functionCode, string $objectName): array
    {
        $config = $this->getPopupConfig($functionCode, $objectName);
        if ($config === null) {
            return ['levels' => [], 'maxLevel' => 1];
        }

        $levelSql = sprintf(
            'select distinct 本级级别, 本级级别名称, 本级初始值, 最大级别
            from %s
            order by 本级级别',
            $config['对象表名']
        );

        $levelResult = $this->model->select($levelSql);
        if ($levelResult === false) {
            return ['levels' => [], 'maxLevel' => 1];
        }

        $levels = [];
        $maxLevel = 1;
        foreach ($levelResult->getResultArray() as $levelRow) {
            $levels[] = [
                'name'         => $levelRow['本级级别名称'],
                'level'        => (int) $levelRow['本级级别'],
                'initialValue' => $levelRow['本级初始值'],
            ];
            $maxLevel = (int) $levelRow['最大级别'];
        }

        return [
            'levels'   => $levels,
            'maxLevel' => $maxLevel,
        ];
    }

    /**
     * 获取弹窗指定级别的数据（懒加载）
     *
     * @param int $level 目标级别
     * @param string $parentCode 上级编码（仅 level>1 时使用）
     * @return array ['items' => array, 'level' => int]
     */
    public function getPopupLevelData(string $functionCode, string $objectName, int $level, string $parentCode = ''): array
    {
        $config = $this->getPopupConfig($functionCode, $objectName);
        if ($config === null) {
            return ['items' => [], 'level' => $level];
        }

        $tableName = $config['对象表名'];

        if ($level === 1) {
            $dataSql = sprintf(
                'select 本级编码, 本级名称, 本级全称,
                    (select count(*) from %1$s as sub where sub.本级级别 = %2$d + 1 and sub.本级全称 like concat(main.本级全称, \'>>%%\')) as has_children
                from %1$s as main
                where main.本级级别 = %2$d
                order by main.本级编码',
                $tableName,
                $level
            );
        } else {
            $dataSql = sprintf(
                'select 本级编码, 本级名称, 本级全称,
                    (select count(*) from %1$s as sub where sub.本级级别 = %2$d + 1 and sub.本级全称 like concat(main.本级全称, \'>>%%\')) as has_children
                from %1$s as main
                where main.本级级别 = %2$d and main.本级全称 like %3$s
                order by main.本级编码',
                $tableName,
                $level,
                $this->model->quote($parentCode . '>>%')
            );
        }

        $dataResult = $this->model->select($dataSql);
        if ($dataResult === false) {
            return ['items' => [], 'level' => $level];
        }

        $items = [];
        foreach ($dataResult->getResultArray() as $dataRow) {
            $items[] = [
                'code'        => $dataRow['本级编码'],
                'name'        => $dataRow['本级名称'],
                'fullName'    => $dataRow['本级全称'],
                'hasChildren' => (int) $dataRow['has_children'] > 0,
            ];
        }

        return [
            'items' => $items,
            'level' => $level,
        ];
    }
}
