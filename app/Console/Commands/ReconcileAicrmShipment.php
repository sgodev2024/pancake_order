<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\AicrmShipmentService;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ReconcileAicrmShipment extends Command
{
    protected $signature = 'aicrm:reconcile {order-id} {--actor-id=}';

    protected $description = 'Link an existing successful Live tracking number using tenant-scoped paginated GET only';

    public function handle(AicrmShipmentService $service): int
    {
        $actorId = filter_var($this->option('actor-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $orderId = filter_var($this->argument('order-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $actor = $actorId ? User::find($actorId) : null;
        $permissions = $actor ? RolePermission::where('role_id', $actor->role_id)
            ->whereIn('permission_id', Permission::whereIn('slug', ['warehouse-view', 'warehouse-pack'])->select('id'))
            ->distinct()->count('permission_id') : 0;
        if (! $orderId || ! $actor || $permissions !== 2) {
            $this->error('Cần đơn hợp lệ và người có đủ quyền xem, xử lý Kho. Không gửi request.');

            return self::FAILURE;
        }
        try {
            $shipment = $service->reconcile($actor, $orderId);
            $this->info('Đã liên kết UUID và trạng thái GET AICRM: '.$shipment->remote_status.'. Không gửi POST.');

            return self::SUCCESS;
        } catch (HttpExceptionInterface $error) {
            $this->error($error->getMessage());
        } catch (Throwable) {
            $this->error('Không thể đối soát AICRM. Không đổi kết quả gửi hoặc mở khóa gửi lại.');
        }

        return self::FAILURE;
    }
}
