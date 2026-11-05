<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderSourceAliasController extends Controller
{
    public function update(Request $request, Shop $shop)
    {
        abort_unless($request->user()->isAdmin(), 403, 'Chỉ Admin được đặt tên gợi nhớ.');
        $data = $request->validate([
            'source_id' => ['required', 'string', 'max:255'],
            'alias' => ['nullable', 'string', 'max:150'],
        ]);
        abort_unless(Order::where('shop_id', $shop->id)->where('pancake_order_page_id', $data['source_id'])->exists(),
            422, 'Nguồn đơn không thuộc cửa hàng này.');
        $key = ['shop_id' => $shop->id, 'source_id' => $data['source_id']];
        $alias = trim($data['alias'] ?? '');
        if ($alias === '') {
            DB::table('order_source_aliases')->where($key)->delete();
        } else {
            DB::table('order_source_aliases')->upsert([
                $key + ['alias' => $alias, 'updated_by_user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()],
            ], ['shop_id', 'source_id'], ['alias', 'updated_by_user_id', 'updated_at']);
        }

        return response()->json(['success' => true, 'message' => 'Đã lưu tên gợi nhớ nguồn đơn.']);
    }
}
