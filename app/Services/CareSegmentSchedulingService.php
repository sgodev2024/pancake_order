<?php
namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CareSegmentSchedulingService
{
    public function pending(Customer $customer)
    {
        return CustomerCareAssignment::query()->where('shop_id', $customer->shop_id)
            ->where('status', CustomerCareAssignment::STATUS_ACTIVE)->whereNull('cared_at')
            ->whereHas('customerCare', fn ($q) => $q->where('status', 0))
            ->where(function ($q) use ($customer): void {
                $q->where(fn ($q) => $q->where('source_type', 'care_segment')->where('source_id', $customer->id))
                    ->orWhere(function ($q) use ($customer): void {
                        $q->where('source_type', 'order')->whereExists(function ($orders) use ($customer): void {
                            $orders->selectRaw('1')->from('orders')->whereColumn('orders.id', 'customer_care_assignments.source_id')
                                ->where('orders.shop_id', $customer->shop_id)->where('orders.customer_id', $customer->id);
                        });
                    });
                if (filled($customer->pancake_customer_id)) {
                    $q->orWhereHas('customerCare', fn ($care) => $care->where('shop_id', $customer->shop_id)
                        ->where('pancake_customer_id', $customer->pancake_customer_id));
                }
            });
    }

    public function save(User $actor, Customer $customer, array $data): array
    {
        return DB::transaction(function () use ($actor, $customer, $data): array {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$customer->id, $data], JSON_UNESCAPED_UNICODE));
            $previous = DB::table('care_segment_entries')->where('actor_user_id', $actor->id)->where('request_id', $data['request_id'])->first();
            if ($previous) {
                abort_unless((int) $previous->customer_id === (int) $customer->id && hash_equals($previous->request_hash, $hash), 409, 'Yêu cầu đã dùng cho khách khác.');
                return ['care_id' => $previous->next_care_id, 'replayed' => true];
            }
            if (filled($customer->pancake_customer_id)) {
                abort_if(Customer::where('shop_id', $customer->shop_id)->where('pancake_customer_id', $customer->pancake_customer_id)->count() > 1,
                    409, 'Có nhiều hồ sơ cùng mã khách. Vui lòng kiểm tra hồ sơ trước.');
                abort_if(CustomerCare::where('shop_id', $customer->shop_id)->where('pancake_customer_id', $customer->pancake_customer_id)
                    ->where('status', 0)->whereDoesntHave('assignments')->exists(),
                    409, 'Khách có lịch cũ chưa xác định phân công. Vui lòng kiểm tra trong Lịch hẹn trước.');
            }
            $pending = $this->pending($customer)->lockForUpdate()->get();
            abort_if($pending->count() > 1, 409, 'Khách có nhiều lịch đang chờ. Vui lòng xử lý lịch hiện có trước.');
            $assignment = $pending->first();
            $manager = $actor->isAdmin() || $actor->isManagerCskh();
            if ($assignment && ! $manager) {
                abort_unless((int) $assignment->assignee_user_id === (int) $actor->id, 409,
                    'Khách đang có lịch do nhân viên khác phụ trách.');
            }
            $assigneeId = $manager ? ($data['assignee_id'] ?? $assignment?->assignee_user_id ?? $actor->id) : $actor->id;
            abort_unless($manager || !isset($data['assignee_id']) || (int)$data['assignee_id'] === (int)$actor->id, 403);
            $assignee = User::findOrFail($assigneeId);
            abort_unless($assignee->canReceiveCustomerCareAssignments()
                && app(ShopAccessService::class)->ids($assignee)->contains((int) $customer->shop_id)
                && ! DB::table('staff_account_states')->where('user_id', $assignee->id)->exists(),
                422, 'Nhân viên phụ trách phải là CSKH đang hoạt động trong cửa hàng.');
            $service = app(CustomerCareAssignmentService::class);
            $at = now();
            $context = trim($data['context'] ?? '');
            $note = '[Lọc CSKH]'.($context !== '' ? "
".$context : '')."
".trim($data['note']);
            if ($assignment) {
                $care = CustomerCare::whereKey($assignment->customer_care_id)->lockForUpdate()->firstOrFail();
                app(CustomerCareWriteAccessService::class)->ensureSourceConsistency($care);
            } else {
                $care = CustomerCare::create([
                    'shop_id' => $customer->shop_id, 'pancake_customer_id' => $customer->pancake_customer_id,
                    'customer_name' => $customer->name, 'customer_phones' => $customer->phone_numbers,
                    'customer_addresss' => $customer->shippingAddress(), 'date_care' => $at->toDateString(),
                    'status' => 0, 'user_creator_id' => $assignee->pancake_user_id,
                ]);
                $assignment = $service->createWithJourneyEvent($care, (int)$customer->shop_id, 'care_segment',
                    (int)$customer->id, $assignee, $at, $at, $actor);
            }
            $care->update(['status' => 1, 'time_care' => $at, 'note' => $note, 'is_accept' => 1,
                'total_edit' => (int)$care->total_edit + 1]);
            $service->markAsCared($care, $at);
            app(CustomerCareCompletionEventWriter::class)->write($care, $assignment, $actor, $at);
            $next = CustomerCare::create([
                'shop_id' => $care->shop_id, 'pancake_customer_id' => $care->pancake_customer_id,
                'pancake_order_id' => $care->pancake_order_id,
                'customer_name' => $customer->name, 'customer_phones' => $customer->phone_numbers,
                'customer_addresss' => $customer->shippingAddress(), 'date_care' => $data['next_date'],
                'note' => $note, 'status' => 0, 'is_accept' => 1, 'user_creator_id' => $assignee->pancake_user_id,
            ]);
            $service->createWithJourneyEvent($next, (int)$customer->shop_id, $assignment->source_type,
                (int)$assignment->source_id, $assignee, $at, Carbon::parse($data['next_date'])->startOfDay(), $actor);
            DB::table('care_segment_entries')->insert([
                'shop_id' => $customer->shop_id, 'customer_id' => $customer->id, 'actor_user_id' => $actor->id,
                'request_id' => $data['request_id'], 'request_hash' => $hash, 'completed_care_id' => $care->id, 'next_care_id' => $next->id,
                'source_type' => $assignment->source_type, 'source_id' => $assignment->source_id,
                'note' => trim($data['note']), 'context' => $context, 'cared_at' => $at,
                'created_at' => $at, 'updated_at' => $at,
            ]);
            ReportDataVersion::bump('customer-care');
            return ['care_id' => $next->id, 'replayed' => false];
        }, 3);
    }
}
