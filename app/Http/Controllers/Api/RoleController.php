<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PermissionGroup;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RoleController extends Controller
{
    // 1. Lấy danh sách Role
    public function index()
    {
        $roles = Role::select("id", "name")->latest()->get();
        $permissionGroups = PermissionGroup::select("id", "name")
                                            ->with(["permissions" => function ($q) {
                                                $q->select("id", "slug as code", "name", "permission_group_id");
                                            }])
                                            ->latest()
                                            ->get();
        $permissionRoles = RolePermission::select("id", "role_id", "permission_id")->get();
        
        return response()->json(['success' => true, 'data' => [
            "roles" => $roles,
            "permissionGroups" => $permissionGroups,
            "permissionRoles" => $permissionRoles
        ]], 200);
    }

    // 2. Thêm Role mới
    public function store(Request $request)
    {
        $request->merge([
            'slug' => Str::slug($request->input('name'))
        ]);
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|unique:roles,name|max:255',
            'slug' => 'required|string|unique:roles,slug|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $role = Role::create($request->only('name', 'slug'));
        return response()->json(['success' => true, 'data' => $role], 201);
    }

    // 3. Xem chi tiết 1 Role
    public function show($id)
    {
        $role = Role::find($id);
        if (!$role) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy Role'], 404);
        }
        return response()->json(['success' => true, 'data' => $role], 200);
    }

    // 4. Cập nhật Role
    public function update(Request $request, $id)
    {
        $role = Role::find($id);
        if (!$role) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy Role'], 404);
        }
        if ($role->slug == "admin") {
            return response()->json(['success' => false, 'message' => 'Quyền này không được phép sửa'], 500);
        }
        if ($request->has('name')) {
            $request->merge([
                'slug' => Str::slug($request->input('name'))
            ]);
        }
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:roles,name,' . $id,
            'slug' => 'required|string|max:255|unique:roles,slug,' . $id,
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $role->update($request->only('name'));
        return response()->json(['success' => true, 'data' => $role], 200);
    }

    // 5. Xóa Role
    public function destroy(string $id)
    {
        $role = Role::find($id);
        if (!$role) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy Role'], 404);
        }
        if ($role->slug == "admin") {
            return response()->json(['success' => false, 'message' => 'Quyền này không được phép xóa'], 500);
        }

        $role->delete();
        return response()->json(['success' => true, 'message' => 'Đã xóa Role thành công'], 200);
    }
}