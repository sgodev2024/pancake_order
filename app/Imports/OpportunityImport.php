<?php

namespace App\Imports;

use App\Models\ImportedOpportunity;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;

class OpportunityImport implements ToModel, WithBatchInserts, WithChunkReading, WithStartRow, SkipsEmptyRows
{
    protected int $shopId;
    protected ?int $importedBy;
    public int $importedCount = 0;

    public function __construct(int $shopId, ?int $importedBy)
    {
        $this->shopId = $shopId;
        $this->importedBy = $importedBy;
    }

    public function startRow(): int
    {
        return 2;
    }

    public function model(array $row)
    {
        $name = trim((string) ($row[0] ?? ""));
        $address = trim((string) ($row[1] ?? ""));
        $phone = trim((string) ($row[2] ?? ""));

        if ($name === "" || $phone === "") {
            return null;
        }

        $this->importedCount++;

        return new ImportedOpportunity([
            "shop_id" => $this->shopId,
            "name" => $name,
            "phone" => $phone,
            "address" => $address !== "" ? $address : null,
            "status" => 0,
            "imported_by" => $this->importedBy,
        ]);
    }

    public function batchSize(): int
    {
        return 500;
    }

    public function chunkSize(): int
    {
        return 500;
    }
}
