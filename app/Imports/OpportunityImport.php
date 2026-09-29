<?php

namespace App\Imports;

use App\Models\ImportedOpportunity;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsFailures;

class OpportunityImport implements
    ToModel,
    WithBatchInserts,
    WithChunkReading,
    WithStartRow,
    SkipsEmptyRows,
    WithValidation,
    SkipsOnFailure
{
    use SkipsFailures;

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

    public function prepareForValidation($row, $index)
    {
        return array_replace([0 => null, 1 => null, 2 => null], $row);
    }

    public function model(array $row)
    {
        $name = trim((string) ($row[0] ?? ""));
        $address = trim((string) ($row[1] ?? ""));
        $phone = trim((string) ($row[2] ?? ""));

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

    public function rules(): array
    {
        return [
            '0' => ['required', 'string', 'max:255'],
            '1' => ['required', 'string', 'max:1000'],
            '2' => ['required', 'regex:/^\d{10}$/'],
        ];
    }

    public function customValidationMessages(): array
    {
        return [
            '0.required' => 'Tên khách hàng là bắt buộc.',
            '0.string' => 'Tên khách hàng phải là văn bản.',
            '0.max' => 'Tên khách hàng không được vượt quá 255 ký tự.',
            '1.required' => 'Địa chỉ là bắt buộc.',
            '1.string' => 'Địa chỉ phải là văn bản.',
            '1.max' => 'Địa chỉ không được vượt quá 1000 ký tự.',
            '2.required' => 'Số điện thoại là bắt buộc.',
            '2.regex' => 'Số điện thoại phải gồm đúng 10 chữ số.',
        ];
    }

    public function customValidationAttributes(): array
    {
        return [
            '0' => 'Tên khách hàng',
            '1' => 'Địa chỉ',
            '2' => 'Số điện thoại',
        ];
    }

    public function failureMessages(): array
    {
        $messagesByRow = [];

        foreach ($this->failures() as $failure) {
            $row = $failure->row();
            $column = $failure->attribute();
            $messages = $failure->errors();
            $messagesByRow[$row][$column] = array_merge(
                $messagesByRow[$row][$column] ?? [],
                $messages
            );
        }

        $formatted = [];
        foreach ($messagesByRow as $row => $columns) {
            $details = [];
            foreach ($columns as $column => $messages) {
                $details[] = $column.': '.implode(' ', array_unique($messages));
            }
            $formatted[] = 'Dòng '.$row.' — '.implode(' ', $details);
        }

        return $formatted;
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
