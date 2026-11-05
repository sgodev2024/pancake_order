<?php

namespace App\Models;

use App\Services\AicrmOrderStatus;
use Illuminate\Database\Eloquent\Model;

class AicrmShipment extends Model
{
    protected $guarded = [];

    protected $hidden = ['reference'];

    protected $casts = ['submitted_at' => 'datetime', 'completed_at' => 'datetime'];

    public function attempts()
    {
        return $this->hasMany(AicrmShipmentAttempt::class, 'shipment_id');
    }

    public function summary(bool $history = false): array
    {
        $data = $this->only(['mode', 'status', 'tracking_number', 'remote_order_id', 'remote_status', 'last_error', 'submitted_at', 'completed_at']);
        $data['remote_status'] = AicrmOrderStatus::valid($this->remote_status) ? $this->remote_status : null;
        $data['remote_status_label'] = AicrmOrderStatus::LABELS[$data['remote_status']] ?? null;
        if ($history) {
            $data['attempts'] = $this->attempts()->orderByDesc('id')->get([
                'id', 'actor_user_id', 'status', 'http_status', 'remote_order_id', 'remote_status', 'error_code', 'error_message', 'created_at', 'completed_at',
            ])->toArray();
        }

        return $data;
    }
}
