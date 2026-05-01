<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ShopUserController extends Controller
{
    /**
     * Thêm user vào shop
     * POST /api/shops/{shop}/users
     */
    public function store(Request $request, Shop $shop)
    {
        // 1. Validate dữ liệu đầu vào
        $validator = Validator::make($request->all(), [
            'user_id'    => 'required|integer|exists:users,id',
            'is_manager' => 'sometimes|boolean' // Yêu cầu là kiểu boolean (true/false, 1/0)
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ',
                'errors'  => $validator->errors()
            ], 422);
        }

        $userId = $validator->validated()['user_id'];
        
        // Mặc định nếu không truyền is_manager lên thì là false (0)
        $isManager = $request->input('is_manager', false); 
        // Ép kiểu cho chắc chắn để insert database không bị lỗi
        $isManager = filter_var($isManager, FILTER_VALIDATE_BOOLEAN); 

        // 2. Kiểm tra logic: Mỗi shop chỉ có 1 manager
        if ($isManager) {
            // Tìm xem shop này đã có ai được set is_manager = true chưa
            $existingManager = $shop->users()->wherePivot('is_manager', true)->first();

            // Nếu đã có manager VÀ manager đó KHÔNG PHẢI là user đang được request lên
            if ($existingManager && $existingManager->id != $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Shop này đã có quản lý (User: ' . $existingManager->name . '). Vui lòng gỡ quyền quản lý cũ trước khi thêm mới.',
                ], 400); // 400 Bad Request
            }
        }

        // 3. Tiến hành thêm/cập nhật vào bảng trung gian
        $syncResult = $shop->users()->syncWithoutDetaching([
            $userId => ['is_manager' => $isManager]
        ]);

        // 4. Trả về response dựa trên kết quả của sync
        if (count($syncResult['attached']) > 0) {
            // Case 1: User chưa có trong shop -> Vừa thêm mới thành công
            return response()->json([
                'success' => true,
                'message' => 'Đã thêm user vào shop thành công',
                'is_manager' => $isManager
            ], 201);
            
        } elseif (count($syncResult['updated']) > 0) {
            // Case 2: User ĐÃ CÓ trong shop, nhưng cột is_manager vừa được cập nhật 
            // (Ví dụ: từ nhân viên bình thường được thăng cấp lên quản lý)
            return response()->json([
                'success' => true,
                'message' => 'Đã cập nhật quyền cho user trong shop',
                'is_manager' => $isManager
            ], 200);
            
        } else {
            // Case 3: User đã ở trong shop và quyền không có gì thay đổi
            return response()->json([
                'success' => false, 
                'message' => 'User này đã tồn tại trong shop với quyền tương tự, không có thay đổi nào.',
            ], 200); 
        }
    }
}
