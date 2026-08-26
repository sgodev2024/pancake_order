<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AdminOnlyMiddleware;
use App\Models\PermissionGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PermissionGroupController extends Controller implements HasMiddleware
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
     * Lấy danh sách nhóm quyền (có thể kèm theo các quyền chi tiết)
     */
    public function index()
    {
        // Sử dụng with('permissions') để tránh lỗi N+1 query khi cần hiện list quyền con
        $groups = PermissionGroup::with('permissions')->get();
        
        return response()->json([
            'success' => true,
            'data' => $groups
        ], 200);
    }

    /**
     * Tạo nhóm quyền mới
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|unique:permission_groups,name|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $group = PermissionGroup::create($request->only('name'));

        return response()->json([
            'success' => true,
            'data' => $group
        ], 201);
    }

    /**
     * Xem chi tiết 1 nhóm quyền
     */
    public function show($id)
    {
        // Tìm nhóm quyền kèm theo danh sách các quyền thuộc nhóm đó
        $group = PermissionGroup::with('permissions')->find($id);

        if (!$group) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy nhóm quyền'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $group
        ], 200);
    }

    /**
     * Cập nhật nhóm quyền
     */
    public function update(Request $request, $id)
    {
        $group = PermissionGroup::find($id);

        if (!$group) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:permission_groups,name,' . $id,
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $group->update($request->only('name'));

        return response()->json([
            'success' => true,
            'data' => $group
        ], 200);
    }

    /**
     * Xóa nhóm quyền (Sẽ cascade xóa các permission con nếu bạn đã set trong Migration)
     */
    public function destroy($id)
    {
        $group = PermissionGroup::find($id);

        if (!$group) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy'], 404);
        }

        $group->delete();

        return response()->json([
            'success' => true,
            'message' => 'Xóa nhóm quyền thành công'
        ], 200);
    }
}
