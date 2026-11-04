<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HuongChat\HuongChatOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PancakeOrderController extends Controller
{
    public function __construct(
        protected HuongChatOrderService $huongChatService
    ) {}

    /**
     * Get orders from Huong Chat Pancake API.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'string', 'max:50'],
            'payment_status' => ['nullable', 'string', 'max:50'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $result = $this->huongChatService->getOrders($request->all());

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'data' => [],
                'pagination' => null,
            ], 502);
        }

        $includeHidden=$request->boolean('include_hidden');
        abort_if($includeHidden && !$request->user()->isAdmin(),403,'Chỉ Admin được xem đơn đã ẩn.');
        $hidden=\Illuminate\Support\Facades\DB::table('hidden_huongchat_orders')->whereIn('order_id',array_column($result['data'],'id'))->pluck('order_id')->map(fn($id)=>(string)$id)->all();
        $rows=array_map(function($row)use($hidden){$row['hidden_in_pancake']=in_array((string)$row['id'],$hidden,true);return $row;},$result['data']);
        if(!$includeHidden)$rows=array_values(array_filter($rows,fn($row)=>!$row['hidden_in_pancake']));
        return response()->json([
            'success' => true,
            'data' => $rows,
            'hidden_on_source_page' => count($hidden),
            'pagination' => $result['pagination'],
        ]);
    }

    /**
     * Get detail of a specific order from Huong Chat.
     */
    public function show(Request $request, $id): JsonResponse
    {
        abort_if(\Illuminate\Support\Facades\DB::table('hidden_huongchat_orders')->where('order_id',$id)->exists() && !$request->user()->isAdmin(),404,'Đơn đã ẩn trong Pancake.');
        $result = $this->huongChatService->getOrderDetail($id);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'data' => null,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => $result['data'],
        ]);
    }

    public function hide(Request $request): JsonResponse {
        abort_unless($request->user()->isAdmin(),403,'Chỉ Admin được ẩn đơn.');
        $data=$request->validate(['ids'=>'required|array|min:1|max:100','ids.*'=>'required|integer|min:1|distinct']);
        \Illuminate\Support\Facades\DB::transaction(function()use($data,$request){
            foreach($data['ids'] as $id) \Illuminate\Support\Facades\DB::table('hidden_huongchat_orders')->insertOrIgnore(['order_id'=>$id,'hidden_by'=>$request->user()->id,'hidden_at'=>now()]);
        });
        return response()->json(['success'=>true,'message'=>'Đã ẩn đơn trong Pancake. Dữ liệu tại Hương Chất giữ nguyên.']);
    }
    public function restore(Request $request,$id): JsonResponse {
        abort_unless($request->user()->isAdmin(),403,'Chỉ Admin được khôi phục đơn.');
        \Illuminate\Support\Facades\DB::table('hidden_huongchat_orders')->where('order_id',$id)->delete();
        return response()->json(['success'=>true,'message'=>'Đã hiển thị lại đơn trong Pancake.']);
    }
}
