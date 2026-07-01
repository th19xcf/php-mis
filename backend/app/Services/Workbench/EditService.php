<?php

namespace App\Services\Workbench;

/**
 * 编辑服务类（门面）
 *
 * 工作台编辑相关业务的统一入口，内部委托给各子服务：
 * - FieldConfigService: 字段配置加载
 * - RecordEditService: 单条记录增删改
 * - BatchEditService: 批量修改与表级编辑
 *
 * 所有对外 API 保持不变，调用方无需感知内部拆分。
 */
class EditService
{
    private FieldConfigService $fieldConfigService;
    private RecordEditService $recordEditService;
    private BatchEditService $batchEditService;

    public function __construct()
    {
        $this->fieldConfigService = new FieldConfigService();
        $this->recordEditService = new RecordEditService();
        $this->batchEditService = new BatchEditService();
    }

    // ==================== 字段配置（委托 FieldConfigService） ====================

    public function getAddFields(string $functionCode, ?string $fieldModule): array
    {
        return $this->fieldConfigService->getAddFields($functionCode, $fieldModule);
    }

    public function getDetailFields(string $functionCode, ?string $fieldModule): array
    {
        return $this->fieldConfigService->getDetailFields($functionCode, $fieldModule);
    }

    public function getBatchEditFields(string $functionCode, ?string $fieldModule): array
    {
        return $this->fieldConfigService->getBatchEditFields($functionCode, $fieldModule);
    }

    public function getUpdateFields(string $functionCode, array $keyValues, string $primaryKey, string $dataTable): array
    {
        return $this->fieldConfigService->getUpdateFields($functionCode, $keyValues, $primaryKey, $dataTable);
    }

    public function getDataTableConfig(string $functionCode, $session): array
    {
        return $this->fieldConfigService->getDataTableConfig($functionCode, $session);
    }

    public function getObjectOptions(string $objectName): array
    {
        return $this->fieldConfigService->getObjectOptions($objectName);
    }

    // ==================== 钩子执行（委托 RecordEditService） ====================

    public function executeBeforeInsert(string $beforeInsert): void
    {
        $this->recordEditService->executeBeforeInsert($beforeInsert);
    }

    public function executeAfterInsert(string $afterInsert, string $primaryKey, array $data): void
    {
        $this->recordEditService->executeAfterInsert($afterInsert, $primaryKey, $data);
    }

    // ==================== WHERE 构建（委托 RecordEditService） ====================

    public function buildWhereFromData(array $data, string $primaryKey): string
    {
        return $this->recordEditService->buildWhereFromData($data, $primaryKey);
    }

    // ==================== 单条新增（委托 RecordEditService） ====================

    public function addRowMode0(string $dataTable, array $data): int
    {
        return $this->recordEditService->addRowMode0($dataTable, $data);
    }

    public function addRowMode1(string $dataTable, array $data, string $userWorkid): int
    {
        return $this->recordEditService->addRowMode1($dataTable, $data, $userWorkid);
    }

    public function addRowMode2(string $dataTable, array $data, string $userWorkid): int
    {
        return $this->recordEditService->addRowMode2($dataTable, $data, $userWorkid);
    }

    // ==================== 单条修改/删除（委托 RecordEditService） ====================

    public function updateRowByModel(
        string $dataTable,
        string $dataModel,
        string $primaryKey,
        array $keyValues,
        array $formData,
        string $userWorkid,
        string $functionCode
    ): int {
        return $this->recordEditService->updateRowByModel(
            $dataTable,
            $dataModel,
            $primaryKey,
            $keyValues,
            $formData,
            $userWorkid,
            $functionCode
        );
    }

    public function deleteRowByModel(
        string $dataTable,
        string $dataModel,
        string $primaryKey,
        array $keyValues,
        string $userWorkid,
        string $functionCode
    ): int {
        return $this->recordEditService->deleteRowByModel(
            $dataTable,
            $dataModel,
            $primaryKey,
            $keyValues,
            $userWorkid,
            $functionCode
        );
    }

    // ==================== 批量操作（委托 BatchEditService） ====================

    public function batchUpdateRowsByModel(
        string $dataTable,
        string $dataModel,
        string $primaryKey,
        array $keyValues,
        array $formData,
        string $userWorkid,
        string $functionCode
    ): int {
        return $this->batchEditService->batchUpdateRowsByModel(
            $dataTable,
            $dataModel,
            $primaryKey,
            $keyValues,
            $formData,
            $userWorkid,
            $functionCode
        );
    }

    public function tableEditByModel(
        string $dataTable,
        string $dataModel,
        string $primaryKey,
        array $rows,
        string $userWorkid,
        string $functionCode
    ): array {
        return $this->batchEditService->tableEditByModel(
            $dataTable,
            $dataModel,
            $primaryKey,
            $rows,
            $userWorkid,
            $functionCode
        );
    }
}
