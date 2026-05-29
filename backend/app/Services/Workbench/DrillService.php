<?php

namespace App\Services\Workbench;

use App\Models\Mcommon;

/**
 * 钻取服务类
 * 负责处理工作台钻取相关的业务逻辑
 */
class DrillService
{
    private Mcommon $model;

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 获取钻取选项
     *
     * @param array $context 上下文信息
     * @param array $payload 请求参数
     * @param string $drillModule 钻取模块
     * @return array
     */
    public function getDrillOptions(array $context, array $payload, string $drillModule): array
    {
        $functionAuth = $context['function'];
        $userAuth = $context['user'];

        if (empty($drillModule)) {
            return [];
        }

        $sql = sprintf(
            'select
                钻取模块,页面选项,t1.功能编码,钻取字段,钻取条件,
                if(t2.二级菜单 is null,"",if(t1.标签副名称="",t2.二级菜单,concat(t2.二级菜单,"-",t1.标签副名称))) as 标签名称,
                t2.功能模块,
                ifnull(t2.一级菜单,"") as menu1,
                ifnull(t2.二级菜单,"") as menu2
            from def_drill_config as t1
            left join def_function as t2 on t1.功能编码=t2.功能编码
            where 钻取模块=%s
            order by 顺序,convert(页面选项 using gbk)',
            $this->quote($drillModule)
        );

        $results = $this->model->select($sql)->getResultArray();
        $options = [];

        foreach ($results as $row) {
            $functionCode = (string) ($row['功能编码'] ?? '');
            if (empty($functionCode)) {
                continue;
            }

            $options[] = [
                'label' => (string) ($row['页面选项'] ?? $row['标签名称'] ?? ''),
                'value' => $functionCode,
                'functionCode' => $functionCode,
                'module' => (string) ($row['功能模块'] ?? ''),
                'drillFields' => (string) ($row['钻取字段'] ?? ''),
                'drillCondition' => (string) ($row['钻取条件'] ?? ''),
                'menu1' => (string) ($row['menu1'] ?? ''),
                'menu2' => (string) ($row['menu2'] ?? '')
            ];
        }

        return $options;
    }

    /**
     * 引用值
     *
     * @param string $value 要引用的值
     * @return string 引用后的值
     */
    private function quote(string $value): string
    {
        return "'" . addslashes($value) . "'";
    }
}
