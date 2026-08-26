<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AdminOnlyMiddleware;
use App\Http\Middleware\RoleReadAccessMiddleware;
use App\Models\PermissionGroup;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class RoleController extends Controller implements HasMiddleware
{
    /**
     * Khai báo middleware cho Controller
     */
    public static function middleware(): array
    {
        return [
            new Middleware(AdminOnlyMiddleware::class, except: ['index', 'options']),
            new Middleware(RoleReadAccessMiddleware::class, only: ['index', 'options']),
        ];
    }

    /**
     * Return only the role fields needed by employee-list filters.
     *
     * This is intentionally separate from the admin role-management index,
     * which also exposes permission groups and role-permission assignments.
     */
    public function options()
    {
        return $this->roleOptionsResponse();
    }

    // 1. Lấy danh sách Role
    public function index(Request $request)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['success' => true, 'data' => [
                'roles' => Role::select('id', 'name', 'slug')->latest()->get(),
                'permissionGroups' => [],
                'permissionRoles' => [],
            ]], 200);
        }

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

    private function roleOptionsResponse()
    {
        return response()->json([
            'success' => true,
            'data' => Role::query()
                ->select('id', 'name', 'slug')
                ->latest()
                ->get(),
        ], 200);
    }

    // 2. Thêm Role mới
    public function store(Request $request)
    {
        
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|unique:roles,name|max:255',
            'code' => [
                'required',
                'string',
                'regex:/^[a-z0-9-]+$/',
                // Bỏ qua các bản ghi đã bị xóa mềm khi check unique
                Rule::unique('roles', 'slug')->withoutTrashed() 
            ],
        ], [
            'name.unique'   => 'Tên quyền đã tồn tại',
            'name.required' => 'Tên quyền là bắt buộc',
            'code.required' => 'Mã quyền là bắt buộc',
            'code.regex'    => 'Mã quyền chỉ được chứa chữ thường không dấu, số và dấu -',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        $role = Role::create([
            "name" => $request->name,
            "slug" => $request->code
        ]);
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
