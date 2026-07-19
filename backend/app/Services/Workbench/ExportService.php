<?php

namespace App\Services\Workbench;

use App\Models\Mcommon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

class ExportService
{
    private Mcommon $model;

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 导出数据为 Excel
     *
     * @param array $columns 列配置
     * @param array $records 数据记录
     * @param string $sheetName 工作表名称
     * @return string Excel 文件路径
     */
    public function exportToExcel(array $columns, array $records, string $sheetName = '数据'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetName);

        $headers = [];
        $headerWidths = [];
        foreach ($columns as $column) {
            $headerName = $column['列名'] ?? $column['名称'] ?? $column['字段名'] ?? '';
            $headers[] = $headerName;
            $headerWidths[] = max(mb_strlen($headerName) * 2, 15);
        }

        $colCount = count($headers);
        $lastColumnLetter = Coordinate::stringFromColumnIndex($colCount);

        for ($i = 0; $i < $colCount; $i++) {
            $colLetter = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($colLetter . '1', $headers[$i]);
        }

        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4A90D9'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CCCCCC'],
                ],
            ],
        ];
        $sheet->getStyle('A1:' . $lastColumnLetter . '1')->applyFromArray($headerStyle);

        foreach ($headerWidths as $index => $width) {
            $columnLetter = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->getColumnDimension($columnLetter)->setWidth($width);
        }

        $rowIndex = 2;
        foreach ($records as $record) {
            for ($i = 0; $i < $colCount; $i++) {
                $fieldName = $columns[$i]['列名'] ?? $columns[$i]['字段名'] ?? '';
                $value = $record[$fieldName] ?? '';
                $colLetter = Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValue($colLetter . $rowIndex, $value);
            }
            $rowIndex++;
        }

        $dataStyle = [
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'EEEEEE'],
                ],
            ],
        ];
        $lastRow = $rowIndex - 1;
        if ($lastRow >= 2) {
            $sheet->getStyle('A2:' . $lastColumnLetter . $lastRow)->applyFromArray($dataStyle);
        }

        $sheet->getRowDimension(1)->setRowHeight(25);

        $filename = 'export_' . date('Ymd_His') . '.xlsx';
        $filePath = WRITEPATH . 'exports/' . $filename;

        if (!file_exists(WRITEPATH . 'exports')) {
            mkdir(WRITEPATH . 'exports', 0777, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        return $filePath;
    }

    /**
     * 导出数据为 CSV
     *
     * @param array $columns 列配置
     * @param array $records 数据记录
     * @return string CSV 文件路径
     */
    public function exportToCsv(array $columns, array $records): string
    {
        $headers = [];
        foreach ($columns as $column) {
            $headerName = $column['列名'] ?? $column['名称'] ?? $column['字段名'] ?? '';
            $headers[] = $headerName;
        }

        $filename = 'export_' . date('Ymd_His') . '.csv';
        $filePath = WRITEPATH . 'exports/' . $filename;

        if (!file_exists(WRITEPATH . 'exports')) {
            mkdir(WRITEPATH . 'exports', 0777, true);
        }

        $handle = fopen($filePath, 'w');

        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($handle, $headers);

        foreach ($records as $record) {
            $rowData = [];
            foreach ($columns as $column) {
                $fieldName = $column['列名'] ?? $column['字段名'] ?? '';
                $value = $record[$fieldName] ?? '';
                $rowData[] = $value;
            }
            fputcsv($handle, $rowData);
        }

        fclose($handle);

        return $filePath;
    }

    /**
     * 流式导出数据为 CSV（大数据量）
     *
     * @param array $columns 列配置
     * @param string $sql 查询 SQL
     * @param int $batchSize 批处理大小
     * @return string CSV 文件路径
     */
    public function exportToCsvStreaming(array $columns, string $sql, int $batchSize = 1000): string
    {
        $headers = [];
        foreach ($columns as $column) {
            $headerName = $column['列名'] ?? $column['名称'] ?? $column['字段名'] ?? '';
            $headers[] = $headerName;
        }

        $filename = 'export_' . date('Ymd_His') . '.csv';
        $filePath = WRITEPATH . 'exports/' . $filename;

        if (!file_exists(WRITEPATH . 'exports')) {
            mkdir(WRITEPATH . 'exports', 0777, true);
        }

        $handle = fopen($filePath, 'w');

        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($handle, $headers);

        $offset = 0;
        while (true) {
            $pagedSql = $sql . " limit $offset, $batchSize";
            $result = $this->model->select($pagedSql);

            if ($result === false) {
                break;
            }

            $records = $result->getResultArray();
            if (empty($records)) {
                break;
            }

            foreach ($records as $record) {
                $rowData = [];
                foreach ($columns as $column) {
                    $fieldName = $column['列名'] ?? $column['字段名'] ?? '';
                    $value = $record[$fieldName] ?? '';
                    $rowData[] = $value;
                }
                fputcsv($handle, $rowData);
            }

            $offset += $batchSize;

            if (count($records) < $batchSize) {
                break;
            }
        }

        fclose($handle);

        return $filePath;
    }

    /**
     * 分批流式导出 CSV
     *
     * 通过 callback 分批拉取数据，每批写入文件后立即释放内存，
     * 支持百万行级导出。
     *
     * @param array $columns 列配置
     * @param callable $fetchRecords 回调函数，签名 (int $offset, int $size): array
     * @param int $batchSize 每批大小
     * @return string CSV 文件路径
     */
    public function exportToCsvBatched(array $columns, callable $fetchRecords, int $batchSize = 1000): string
    {
        $headers = [];
        foreach ($columns as $column) {
            $headerName = $column['列名'] ?? $column['名称'] ?? $column['字段名'] ?? '';
            $headers[] = $headerName;
        }

        $filename = 'export_' . date('Ymd_His') . '.csv';
        $filePath = WRITEPATH . 'exports/' . $filename;

        if (!file_exists(WRITEPATH . 'exports')) {
            mkdir(WRITEPATH . 'exports', 0777, true);
        }

        $handle = fopen($filePath, 'w');

        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($handle, $headers);

        $offset = 0;
        $totalRows = 0;

        while (true) {
            $records = $fetchRecords($offset, $batchSize);

            if (empty($records)) {
                break;
            }

            foreach ($records as $record) {
                $rowData = [];
                foreach ($columns as $column) {
                    $fieldName = $column['列名'] ?? $column['字段名'] ?? '';
                    $value = $record[$fieldName] ?? '';
                    $rowData[] = $value;
                }
                fputcsv($handle, $rowData);
            }

            $totalRows += count($records);
            $offset += $batchSize;

            if (count($records) < $batchSize) {
                break;
            }
        }

        fclose($handle);

        log_message('info', sprintf('[ExportService] CSV 流式导出完成: %d 行', $totalRows));

        return $filePath;
    }

    /**
     * 分批流式导出 Excel
     *
     * 通过 callback 分批拉取数据写入 Spreadsheet，避免一次性加载全部记录。
     * 注意：PhpSpreadsheet 本身会在内存中构建完整文档，对超大数据量
     * （>100000 行）建议使用 CSV 格式。
     *
     * @param array $columns 列配置
     * @param callable $fetchRecords 回调函数，签名 (int $offset, int $size): array
     * @param string $sheetName 工作表名称
     * @param int $batchSize 每批大小
     * @param array $mergeColumns 需要跨行合并的列名列表（连续相同值合并）
     * @return string Excel 文件路径
     */
    public function exportToExcelBatched(array $columns, callable $fetchRecords, string $sheetName = '数据', int $batchSize = 1000, array $mergeColumns = []): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetName);

        $headers = [];
        $headerWidths = [];
        foreach ($columns as $column) {
            $headerName = $column['列名'] ?? $column['名称'] ?? $column['字段名'] ?? '';
            $headers[] = $headerName;
            $headerWidths[] = max(mb_strlen($headerName) * 2, 15);
        }

        $colCount = count($headers);
        $lastColumnLetter = Coordinate::stringFromColumnIndex($colCount);

        for ($i = 0; $i < $colCount; $i++) {
            $colLetter = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($colLetter . '1', $headers[$i]);
        }

        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4A90D9'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CCCCCC'],
                ],
            ],
        ];
        $sheet->getStyle('A1:' . $lastColumnLetter . '1')->applyFromArray($headerStyle);

        foreach ($headerWidths as $index => $width) {
            $columnLetter = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->getColumnDimension($columnLetter)->setWidth($width);
        }

        // 构造合并列追踪器：每个合并列记录当前合并区间的起始行号与值
        // 流式分批写入时跨批维持状态，遇到不同值则关闭上一区间并开启新区间
        $mergeTrackers = [];
        foreach ($mergeColumns as $colName) {
            $colIdx = array_search($colName, $headers, true);
            if ($colIdx !== false) {
                $mergeTrackers[] = [
                    'colIdx' => $colIdx,
                    'startRow' => null,
                    'currentValue' => null,
                ];
            }
        }

        $rowIndex = 2;
        $offset = 0;
        $totalRows = 0;

        while (true) {
            $records = $fetchRecords($offset, $batchSize);

            if (empty($records)) {
                break;
            }

            foreach ($records as $record) {
                for ($i = 0; $i < $colCount; $i++) {
                    $fieldName = $columns[$i]['列名'] ?? $columns[$i]['字段名'] ?? '';
                    $value = $record[$fieldName] ?? '';
                    $colLetter = Coordinate::stringFromColumnIndex($i + 1);
                    $sheet->setCellValue($colLetter . $rowIndex, $value);
                }

                // 更新合并追踪器：相邻同值合并，不同值则关闭前一区间
                foreach ($mergeTrackers as &$tracker) {
                    $fieldName = $columns[$tracker['colIdx']]['列名'] ?? $columns[$tracker['colIdx']]['字段名'] ?? '';
                    $value = (string) ($record[$fieldName] ?? '');

                    if ($tracker['startRow'] === null) {
                        $tracker['startRow'] = $rowIndex;
                        $tracker['currentValue'] = $value;
                    } elseif ($value !== $tracker['currentValue']) {
                        // 关闭前一区间（仅当跨越多于 1 行时才需要 merge）
                        if ($rowIndex - 1 > $tracker['startRow']) {
                            $colLetter = Coordinate::stringFromColumnIndex($tracker['colIdx'] + 1);
                            $sheet->mergeCells($colLetter . $tracker['startRow'] . ':' . $colLetter . ($rowIndex - 1));
                        }
                        $tracker['startRow'] = $rowIndex;
                        $tracker['currentValue'] = $value;
                    }
                }
                unset($tracker);

                $rowIndex++;
            }

            $totalRows += count($records);
            $offset += $batchSize;

            if (count($records) < $batchSize) {
                break;
            }
        }

        // 关闭所有未关闭的合并区间（最后一批数据）
        $lastRow = $rowIndex - 1;
        foreach ($mergeTrackers as $tracker) {
            if ($tracker['startRow'] !== null && $lastRow > $tracker['startRow']) {
                $colLetter = Coordinate::stringFromColumnIndex($tracker['colIdx'] + 1);
                $sheet->mergeCells($colLetter . $tracker['startRow'] . ':' . $colLetter . $lastRow);
            }
        }

        $dataStyle = [
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'EEEEEE'],
                ],
            ],
        ];
        if ($lastRow >= 2) {
            $sheet->getStyle('A2:' . $lastColumnLetter . $lastRow)->applyFromArray($dataStyle);
        }

        $sheet->getRowDimension(1)->setRowHeight(25);

        if (!empty($mergeTrackers)) {
            log_message('info', sprintf('[ExportService] 跨行合并已应用: %d 列', count($mergeTrackers)));
        }

        $filename = 'export_' . date('Ymd_His') . '.xlsx';
        $filePath = WRITEPATH . 'exports/' . $filename;

        if (!file_exists(WRITEPATH . 'exports')) {
            mkdir(WRITEPATH . 'exports', 0777, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        log_message('info', sprintf('[ExportService] Excel 流式导出完成: %d 行', $totalRows));

        return $filePath;
    }

    /**
     * 生成导出文件名
     *
     * @param string $functionCode 功能编码
     * @param string $format 格式（xlsx/csv）
     * @return string 文件名
     */
    public function generateFilename(string $functionCode, string $format): string
    {
        $timestamp = date('Ymd_His');
        return "{$functionCode}_{$timestamp}.{$format}";
    }

    /**
     * 清理过期导出文件
     *
     * @param int $maxAgeHours 最大保留时间（小时）
     */
    public function cleanupExpiredExports(int $maxAgeHours = 24): void
    {
        $dir = WRITEPATH . 'exports';
        if (!is_dir($dir)) {
            return;
        }

        $maxAgeSeconds = $maxAgeHours * 3600;
        $now = time();

        foreach (glob($dir . '/*.{xlsx,csv}', GLOB_BRACE) as $file) {
            if (is_file($file) && ($now - filemtime($file)) > $maxAgeSeconds) {
                unlink($file);
            }
        }
    }
}
