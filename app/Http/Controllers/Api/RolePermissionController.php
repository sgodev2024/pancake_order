<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AdminOnlyMiddleware;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class RolePermissionController extends Controller implements HasMiddleware
{
    /**
     * Khai báo middleware cho Controller
     */
    public static function middleware(): array
    {
        return [
            // Khai báo lần lượt từng middleware và chỉ định áp dụng cho method 'store'
            new Middleware(AdminOnlyMiddleware::class),
        ];
    }

    /**
     * Lấy danh sách các quyền của một Role cụ thể
     */
    public function show($roleId)
    {
        $role = Role::with('permissions.permissionGroup')->find($roleId);

        if (!$role) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy Role'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $role->permissions
        ], 200);
    }

    /**
     * Cập nhật/Gán danh sách quyền cho một Role (Sử dụng Store để Sync)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'role_id' => 'required|exists:roles,id',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $role = Role::findOrFail($request->role_id);

        // Hàm sync() sẽ tự động:
        // 1. Thêm những ID mới
        // 2. Xóa những ID cũ không có trong mảng gửi lên
        // 3. Giữ lại những ID đang có
        $role->permissions()->sync($request->permission_ids ?? []);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật quyền cho vai trò thành công',
            'data' => $role->load('permissions')
        ], 200);
    }

    /**
     * Xóa toàn bộ quyền của một Role
     */
    public function destroy($roleId)
    {
        $role = Role::find($roleId);
        if (!$role) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy Role'], 404);
        }

        // Ngắt toàn bộ liên kết quyền
        $role->permissions()->detach();

        return response()->json([
            'success' => true,
            'message' => 'Đã thu hồi toàn bộ quyền của vai trò này'
        ], 200);
    }
}
