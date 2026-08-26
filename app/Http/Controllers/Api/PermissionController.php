<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AdminOnlyMiddleware;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class PermissionController extends Controller implements HasMiddleware
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
    
    // 1. Lấy danh sách tất cả các quyền (kèm theo tên nhóm để dễ nhìn)
    public function index()
    {
        $permissions = Permission::with('permissionGroup')->get();
        return response()->json(['success' => true, 'data' => $permissions], 200);
    }

    // 2. Thêm một quyền mới vào một nhóm
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'permission_group_id' => 'required|exists:permission_groups,id',
            'name' => 'required|string|max:255',
            'slug' => [
                'required',
                'string',
                // Bỏ qua các bản ghi đã bị xóa mềm khi check unique
                Rule::unique('permissions', 'slug')->withoutTrashed() 
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $permission = Permission::create([
            'permission_group_id' => $request->permission_group_id,
            'name' => $request->name,
            'slug' => Str::slug($request->slug), // Đảm bảo slug chuẩn định dạng
        ]);

        return response()->json(['success' => true, 'data' => $permission], 201);
    }

    // 3. Chi tiết 1 quyền
    public function show($id)
    {
        $permission = Permission::with('permissionGroup')->find($id);
        if (!$permission) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy'], 404);
        }
        return response()->json(['success' => true, 'data' => $permission], 200);
    }

    // 4. Cập nhật quyền
    public function update(Request $request, $id)
    {
        $permission = Permission::find($id);
        if (!$permission) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy'], 404);
        }

        $validator = Validator::make($request->all(), [
            'permission_group_id' => 'sometimes|exists:permission_groups,id',
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|unique:permissions,slug,' . $id,
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $permission->update($request->all());

        return response()->json(['success' => true, 'data' => $permission], 200);
    }

    // 5. Xóa quyền
    public function destroy($id)
    {
        $permission = Permission::find($id);
        if (!$permission) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy'], 404);
        }

        $permission->delete();
        return response()->json(['success' => true, 'message' => 'Đã xóa quyền thành công'], 200);
    }
}
