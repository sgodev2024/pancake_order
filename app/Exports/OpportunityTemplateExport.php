<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class OpportunityTemplateExport implements FromArray, WithHeadings
{
    public function array(): array
    {
        return [
            ["Nguyễn Văn A", "123 Đường ABC, Quận 1, TP.HCM", "0901234567"],
        ];
    }

    public function headings(): array
    {
        return ["Tên", "Địa chỉ", "Số điện thoại"];
    }
}
