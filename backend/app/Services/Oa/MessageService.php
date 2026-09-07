<?php

namespace App\Services\Oa;

use App\Exceptions\BusinessException;
use App\Models\Mcommon;

/**
 * 站内消息服务（oa_message）
 *
 * 用途：待办中心各类动作的触达通知（催办/转办/新待办/完成/取消/评论/到期提醒）
 * 供 TodoService 等调用，Header 角标轮询未读数
 */
class MessageService
{
    private Mcommon $model;

    /** 消息类型选项 */
    public const MSG_TYPES = ['催办', '转办', '新待办', '已完成', '已取消', '评论', '到期提醒', '逾期提醒'];

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 发送站内消息（单发）
     *
     * @param string $receiver  接收人工号
     * @param string $type      消息类型
     * @param string $title     标题
     * @param string $content   内容
     * @param int|null $todoGuid 关联待办GUID
     */
    public function send(string $receiver, string $type, string $title, string $content = '', ?int $todoGuid = null): void
    {
        $receiver = trim($receiver);
        if ($receiver === '') {
            return;
        }
        try {
            $this->insertRow('oa_message', [
                '接收人' => $receiver,
                '接收人姓名' => $this->fetchUserName($receiver),
                '消息类型' => $type,
                '标题' => mb_substr($title, 0, 200),
                '内容' => $content !== '' ? mb_substr($content, 0, 500) : null,
                '关联待办GUID' => $todoGuid,
                '已读标识' => '0',
                '创建时间' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 消息发送失败不阻断主流程
            log_message('error', '[MessageService::send] ' . $e->getMessage());
        }
    }

    /**
     * 群发（多接收人）
     *
     * @param array $receivers 工号列表
     */
    public function sendBatch(array $receivers, string $type, string $title, string $content = '', ?int $todoGuid = null): void
    {
        foreach (array_unique(array_filter($receivers)) as $r) {
            $this->send((string) $r, $type, $title, $content, $todoGuid);
        }
    }

    /**
     * 我的消息列表
     *
     * @param string $workId     当前用户工号
     * @param bool   $unreadOnly 仅未读
     * @param int    $page       页码
     * @param int    $pageSize   每页
     * @return array{list:array, total:int, unread:int}
     */
    public function list(string $workId, bool $unreadOnly = false, int $page = 1, int $pageSize = 20): array
    {
        $page = max(1, $page);
        $pageSize = min(50, max(1, $pageSize));
        $workId = $this->model->quote($workId);

        $where = "接收人={$workId}";
        if ($unreadOnly) {
            $where .= ' and 已读标识="0"';
        }

        $total = (int) ($this->model->select("select count(*) as c from oa_message where {$where}")->getRowArray()['c'] ?? 0);
        $unread = (int) ($this->model->select("select count(*) as c from oa_message where 接收人={$workId} and 已读标识=\"0\"")->getRowArray()['c'] ?? 0);

        $rows = $this->model->select(sprintf(
            'select GUID, 消息类型 as type, 标题 as title, 内容 as content, 关联待办GUID as todoGuid,
                    已读标识 as isRead, 阅读时间 as readAt, 创建时间 as createdAt
             from oa_message where %s order by GUID desc limit %d offset %d',
            $where,
            $pageSize,
            ($page - 1) * $pageSize
        ))->getResultArray();

        return ['list' => $rows, 'total' => $total, 'unread' => $unread];
    }

    /**
     * 未读数
     */
    public function unreadCount(string $workId): int
    {
        return (int) ($this->model->select(sprintf(
            'select count(*) as c from oa_message where 接收人=%s and 已读标识="0"',
            $this->model->quote($workId)
        ))->getRowArray()['c'] ?? 0);
    }

    /**
     * 标记已读（支持批量/全部）
     *
     * @param array|null $ids 消息GUID列表（null=全部已读）
     */
    public function markRead(string $workId, ?array $ids = null): int
    {
        $workId = $this->model->quote($workId);
        $now = $this->model->quote(date('Y-m-d H:i:s'));
        if ($ids === null || $ids === []) {
            $this->model->exec("update oa_message set 已读标识=\"1\", 阅读时间={$now} where 接收人={$workId} and 已读标识=\"0\"");
        } else {
            $clean = array_values(array_filter($ids, fn($i) => ctype_digit((string) $i)));
            if ($clean === []) {
                throw new BusinessException('消息ID无效');
            }
            $in = implode(',', array_map(fn($i) => (int) $i, $clean));
            $this->model->exec("update oa_message set 已读标识=\"1\", 阅读时间={$now} where 接收人={$workId} and GUID in ({$in})");
        }
        return (int) $this->model->getDb()->affectedRows();
    }

    /**
     * 读取消息时顺带标记（点开即读）
     */
    public function readOne(string $workId, int $guid): void
    {
        $this->markRead($workId, [$guid]);
    }

    /**
     * 查询工号对应姓名
     */
    private function fetchUserName(string $workId): ?string
    {
        $row = $this->model->select(sprintf(
            'select 姓名 from def_user where 工号=%s and 有效标识="1" limit 1',
            $this->model->quote($workId)
        ))->getRowArray();
        return $row ? (string) $row['姓名'] : null;
    }

    /**
     * 通用插入（与 TodoService 保持一致的写法）
     *
     * @param array $row 列名 => 值
     */
    private function insertRow(string $table, array $row): void
    {
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($row)));
        $vals = implode(', ', array_map(
            fn($v) => is_int($v) ? (string) $v : ($v === null ? 'null' : $this->model->quote((string) $v)),
            array_values($row)
        ));
        $this->model->exec("insert into {$table} ({$cols}) values ({$vals})");
    }
}
