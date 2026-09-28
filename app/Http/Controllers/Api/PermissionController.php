<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AdminOnlyMiddleware;
use App\Models\Permission;
use App\Models\PermissionGroup;
use Illuminate\Support\Facades\DB;
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

    public function bulkUpdate(Request $request)
    {
        $normalized = [];
        foreach (['groups' => 'name', 'permissions' => 'name'] as $collection => $field) {
            $items = $request->input($collection);
            if (! is_array($items)) {
                continue;
            }

            $normalized[$collection] = array_map(function ($item) use ($field) {
                if (! is_array($item)) {
                    return $item;
                }

                foreach ([$field, 'slug'] as $valueField) {
                    if (isset($item[$valueField]) && is_string($item[$valueField])) {
                        $item[$valueField] = trim($item[$valueField]);
                    }
                }

                return $item;
            }, $items);
        }
        $request->merge($normalized);

        $validator = Validator::make($request->all(), [
            'groups' => ['sometimes', 'array'],
            'groups.*.id' => ['required', 'integer', 'distinct', 'exists:permission_groups,id'],
            'groups.*.name' => ['required', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*.id' => ['required', 'integer', 'distinct', 'exists:permissions,id'],
            'permissions.*.permission_group_id' => ['required', 'integer', 'exists:permission_groups,id'],
            'permissions.*.name' => ['required', 'string', 'max:255'],
            'permissions.*.slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/'],
        ], [
            'groups.*.name.required' => 'Tên nhóm quyền là bắt buộc.',
            'permissions.*.permission_group_id.required' => 'Vui lòng chọn nhóm quyền.',
            'permissions.*.name.required' => 'Tên quyền là bắt buộc.',
            'permissions.*.slug.required' => 'Mã quyền là bắt buộc.',
            'permissions.*.slug.regex' => 'Mã quyền chỉ được chứa chữ thường không dấu, số và dấu gạch ngang.',
        ]);

        $groupUpdates = collect($request->input('groups', []));
        $permissionUpdates = collect($request->input('permissions', []));

        $validator->after(function ($validator) use ($groupUpdates, $permissionUpdates) {
            if ($validator->errors()->count() > 0) {
                return;
            }

            if ($groupUpdates->isEmpty() && $permissionUpdates->isEmpty()) {
                $validator->errors()->add('catalog', 'Không có thay đổi nào để lưu.');

                return;
            }

            $changedGroupIds = $groupUpdates->pluck('id')->map(fn ($id) => (int) $id)->all();
            $finalGroupNames = PermissionGroup::query()->pluck('name', 'id')->all();
            foreach ($groupUpdates as $group) {
                if (isset($group['id'], $group['name'])) {
                    $finalGroupNames[$group['id']] = $group['name'];
                }
            }

            $groupNameIds = [];
            foreach ($finalGroupNames as $id => $name) {
                $groupNameIds[mb_strtolower(trim($name))][] = (int) $id;
            }
            foreach ($groupNameIds as $ids) {
                if (count($ids) < 2) {
                    continue;
                }
                foreach ($groupUpdates as $index => $group) {
                    if (in_array((int) $group['id'], $ids, true)) {
                        $validator->errors()->add("groups.{$index}.name", 'Tên nhóm quyền đã tồn tại.');
                    }
                }
            }

            $changedPermissionIds = $permissionUpdates->pluck('id')->map(fn ($id) => (int) $id)->all();
            $finalPermissionSlugs = Permission::withTrashed()->pluck('slug', 'id')->all();
            foreach ($permissionUpdates as $permission) {
                if (isset($permission['id'], $permission['slug'])) {
                    $finalPermissionSlugs[$permission['id']] = $permission['slug'];
                }
            }

            $permissionSlugIds = [];
            foreach ($finalPermissionSlugs as $id => $slug) {
                $permissionSlugIds[strtolower(trim($slug))][] = (int) $id;
            }
            foreach ($permissionSlugIds as $ids) {
                if (count($ids) < 2) {
                    continue;
                }
                foreach ($permissionUpdates as $index => $permission) {
                    if (in_array((int) $permission['id'], $ids, true)) {
                        $validator->errors()->add("permissions.{$index}.slug", 'Mã quyền đã tồn tại.');
                    }
                }
            }
        });

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($groupUpdates, $permissionUpdates) {
            foreach ($groupUpdates as $group) {
                PermissionGroup::query()->whereKey($group['id'])->update([
                    'name' => '__bulk_group_'.Str::uuid(),
                ]);
            }
            foreach ($permissionUpdates as $permission) {
                Permission::query()->whereKey($permission['id'])->update([
                    'slug' => '__bulk_permission_'.Str::uuid(),
                ]);
            }

            foreach ($groupUpdates as $group) {
                PermissionGroup::query()->whereKey($group['id'])->update([
                    'name' => $group['name'],
                    'updated_at' => now(),
                ]);
            }
            foreach ($permissionUpdates as $permission) {
                Permission::query()->whereKey($permission['id'])->update([
                    'permission_group_id' => $permission['permission_group_id'],
                    'name' => $permission['name'],
                    'slug' => $permission['slug'],
                    'updated_at' => now(),
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Đã cập nhật danh mục quyền thành công.',
            'data' => [
                'groups_updated' => $groupUpdates->count(),
                'permissions_updated' => $permissionUpdates->count(),
            ],
        ], 200);
    }

    // 2. Thêm một quyền mới vào một nhóm
    public function store(Request $request)
    {
        $normalized = [];
        foreach (['name', 'slug'] as $field) {
            if (is_string($request->input($field))) {
                $normalized[$field] = trim($request->input($field));
            }
        }
        $request->merge($normalized);

        $validator = Validator::make($request->all(), [
            'permission_group_id' => 'required|exists:permission_groups,id',
            'name' => 'required|string|max:255',
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('permissions', 'slug'),
            ],
        ], [
            'name.required' => 'Tên quyền là bắt buộc.',
            'slug.required' => 'Mã quyền là bắt buộc.',
            'slug.regex' => 'Mã quyền chỉ được chứa chữ thường không dấu, số và dấu gạch ngang.',
            'slug.unique' => 'Mã quyền đã tồn tại.',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $permission = Permission::create([
            'permission_group_id' => $request->permission_group_id,
            'name' => $request->name,
            'slug' => Str::slug($request->slug),
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

        $normalized = [];
        foreach (['name', 'slug'] as $field) {
            if (is_string($request->input($field))) {
                $normalized[$field] = trim($request->input($field));
            }
        }
        $request->merge($normalized);

        $validator = Validator::make($request->all(), [
            'permission_group_id' => ['sometimes', 'required', 'exists:permission_groups,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('permissions', 'slug')->ignore($permission->id),
            ],
        ], [
            'name.required' => 'Tên quyền là bắt buộc.',
            'slug.required' => 'Mã quyền là bắt buộc.',
            'slug.regex' => 'Mã quyền chỉ được chứa chữ thường không dấu, số và dấu gạch ngang.',
            'slug.unique' => 'Mã quyền đã tồn tại.',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $permission->update($request->only('permission_group_id', 'name', 'slug'));

        return response()->json(['success' => true, 'data' => $permission], 200);
    }

    // 5. Xóa quyền
    public function destroy($id)
    {
        $permission = Permission::find($id);
        if (!$permission) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy'], 404);
        }

        DB::transaction(function () use ($permission) {
            DB::table('role_permissions')
                ->where('permission_id', $permission->id)
                ->delete();
            $permission->delete();
        });
        return response()->json(['success' => true, 'message' => 'Đã xóa quyền thành công'], 200);
    }
}
