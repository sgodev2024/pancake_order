<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CareSegmentService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CareSegmentController extends Controller
{
    private function authorizeCare(Request $request, \App\Models\Customer $customer): void
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || can_access('care-segment-view'), 403);
        app(\App\Services\CustomerCareWriteAccessService::class)->authorizeFeature($user);
        abort_unless(app(\App\Services\ShopAccessService::class)->canAccessShop($user, (int)$customer->shop_id), 403);
    }

    public function careOptions(Request $request, \App\Models\Customer $customer, \App\Services\CareSegmentSchedulingService $service)
    {
        $this->authorizeCare($request, $customer);
        $staff = \App\Models\User::whereHas('shops', fn ($q) => $q->where('shops.id', $customer->shop_id))
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['staff-cskh', 'manager-cskh']))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('staff_account_states')->whereColumn('user_id', 'users.id'))
            ->get(['id', 'name']);
        $pending = $service->pending($customer)->with('customerCare')->get()->map(fn ($row) => [
            'id' => $row->customer_care_id, 'assignee_id' => $row->assignee_user_id, 'date' => $row->customerCare?->date_care,
        ]);
        return response()->json(['success' => true, 'data' => ['staff' => $staff, 'pending' => $pending]]);
    }

    public function saveCare(Request $request, \App\Models\Customer $customer, \App\Services\CareSegmentSchedulingService $service)
    {
        $this->authorizeCare($request, $customer);
        $data = $request->validate([
            'request_id' => ['required', 'uuid'], 'note' => ['required', 'string', 'max:5000'],
            'context' => ['nullable', 'string', 'max:2000'],
            'next_date' => ['required', 'date_format:Y-m-d'],
            'assignee_id' => ['nullable', 'integer', 'min:1'],
        ]);
        abort_if(trim($data['note']) === '', 422, 'Nhập nội dung chăm sóc.');
        try {
            $result = $service->save($request->user(), $customer, $data);
        } catch (\DomainException $error) {
            abort(409, $error->getMessage());
        }
        return response()->json(['success' => true, 'message' => 'Đã ghi nhận chăm sóc và lịch hẹn tiếp theo.', 'data' => $result]);
    }

    public function index(Request $request, CareSegmentService $service)
    {
        $actor = $request->user();
        abort_unless($actor->isAdmin() || can_access('care-segment-view'), 403);
        $data = $request->validate([
            'shop_ids' => ['nullable', 'array', 'max:100'], 'shop_ids.*' => ['integer', 'min:1', 'distinct'],
            'month_from' => ['required', 'date_format:Y-m'], 'month_to' => ['required', 'date_format:Y-m', 'after_or_equal:month_from'],
            'order_page_ids' => ['nullable', 'array', 'max:100'], 'order_page_ids.*' => ['string', 'max:255', 'distinct'],
            'products' => ['nullable', 'array', 'max:100'], 'products.*' => ['string', 'size:40'],
            'match' => ['nullable', 'in:any,all'], 'quantity_min' => ['nullable', 'numeric', 'min:0'],
            'quantity_max' => ['nullable', 'numeric', 'min:0'],
            'last_purchase_before' => ['nullable', 'date_format:Y-m-d'], 'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'], 'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sources_only' => ['nullable', 'boolean'],
            'export' => ['nullable', 'boolean'], 'selected' => ['nullable', 'array', 'max:10000'],
            'selected.*' => ['string', 'max:80'],
        ]);
        abort_if(isset($data['quantity_min'], $data['quantity_max']) && $data['quantity_max'] < $data['quantity_min'],
            422, 'Số lượng tối đa phải lớn hơn hoặc bằng tối thiểu.');
        abort_if(\Carbon\Carbon::parse($data['month_from'].'-01')->diffInMonths(\Carbon\Carbon::parse($data['month_to'].'-01')) > 35,
            422, 'Mỗi lần lọc tối đa 36 tháng.');
        if (! empty($data['export'])) {
            abort_unless($actor->isAdmin() || can_access('care-segment-export'), 403);
        }
        $report = $service->report($actor, $data);
        $rows = $report['rows'];
        if (! empty($data['export'])) {
            if (! empty($data['selected'])) {
                $rows = array_values(array_filter($rows, fn ($r) => in_array($r['key'], $data['selected'], true)));
            }
            return response()->streamDownload(function () use ($rows): void {
                $book = new Spreadsheet();
                $sheet = $book->getActiveSheet();
                $sheet->fromArray([['Cửa hàng', 'Khách hàng', 'Số điện thoại', 'Nhân viên phụ trách hồ sơ',
                    'Sản phẩm và số lượng', 'Tổng số lượng', 'Số đơn', 'Lần mua gần nhất trong kỳ']]);
                foreach ($rows as $index => $row) {
                    $values = [$row['shop_name'], $row['name'], $row['phone'], $row['staff_name'],
                        implode('; ', array_map(fn ($p) => $p['name'].' × '.$p['quantity'], $row['products'])),
                        $row['quantity'], $row['order_count'], $row['last_purchase_at']];
                    foreach ($values as $column => $value) {
                        $sheet->setCellValueExplicit([$column + 1, $index + 2], (string) $value, DataType::TYPE_STRING);
                    }
                }
                $sheet->freezePane('A2');
                $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
                (new Xlsx($book))->save('php://output');
                $book->disconnectWorksheets();
            }, 'loc-cskh.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 30;
        return response()->json(['success' => true, 'data' => [
            'customers' => array_slice($rows, ($page - 1) * $size, $size),
            'total' => count($rows), 'products' => $report['products'], 'sources' => $report['sources'],
            'unmatched_orders' => $report['unmatched_orders'],
        ]]);
    }
}
