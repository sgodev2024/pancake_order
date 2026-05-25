<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerCareController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PermissionGroupController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RolePermissionController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\ShopCustomerController;
use App\Http\Controllers\Api\ShopOrderController;
use App\Http\Controllers\Api\ShopUserController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\LoyaltyTierController;
use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/test', function () {
    $t = \App\Models\CustomerCare::find(31);
    dd($t->users);
});
// --- Nhóm các Route Public (Không cần Token) ---
Route::prefix('v1/auth')->group(function () {
    // Đăng ký tài khoản mới
    Route::post('register', [AuthController::class, 'register']);
    
    // Đăng nhập lấy Token
    Route::post('login', [AuthController::class, 'login']);
    
    // Quên mật khẩu (Gửi mail reset)
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    
    // Reset mật khẩu mới
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});
Route::get('/v1/settings/{code}', [SettingController::class, "show"]);
// --- Nhóm các Route Private (Yêu cầu Passport Token) ---
Route::middleware('auth:api')->prefix('v1')->group(function () {
    Route::post('/change-first-password', [AuthController::class, 'changeFirstPassword']);
    // Nhóm tài khoản (Profile)
    Route::prefix('me')->group(function () {
        // Lấy thông tin tài khoản đang đăng nhập
        Route::get('/', [AuthController::class, 'profile']);
        Route::put('/update', [UserController::class, "updateProfile"]);
        
        // Cập nhật thông tin cá nhân
        //Route::put('update', [AuthController::class, 'updateProfile']);
        
        // Đổi mật khẩu
        //Route::post('change-password', [AuthController::class, 'changePassword']);
        
        // Đăng xuất (Hủy Token)
        Route::post('logout', [AuthController::class, 'logout']);
    });

    // Các router quản lý dự án, nhân sự khác sẽ nằm ở đây...
    Route::apiResource('roles', RoleController::class);
    Route::apiResource('permission-groups', PermissionGroupController::class);
    Route::apiResource('permissions', PermissionController::class);
    Route::apiResource('role-permissions', RolePermissionController::class);
    
    Route::post("shops/{shop_id}/update-employee-from-pancake", [ShopController::class, 'updateEmployeeFromPancake']);
    Route::apiResource('shops', ShopController::class);
    Route::apiResource('shops.customers', ShopCustomerController::class);
    Route::apiResource('shops.orders', ShopOrderController::class);
    Route::apiResource('shops.users', ShopUserController::class);

    Route::apiResource("orders", OrderController::class);
    Route::apiResource("customers", CustomerController::class);
    Route::get("customers/{pancake_customer_id}/orders", [CustomerController::class, "getOrder"]);
    Route::apiResource("products", ProductController::class);

    Route::get("users/all-user", [UserController::class, "getAllUser"]);
    Route::apiResource('users', UserController::class);
    Route::get("users/{pancake_user_id}/orders", [UserController::class, "getOrder"]);
    
    Route::post('customer-cares/{id}/confirm', [CustomerCareController::class, "confirmCare"]);
    Route::post('customer-cares/{id}/assign', [CustomerCareController::class, "assign"]);
    Route::post('customer-cares/{id}/accept', [CustomerCareController::class, "accept"]);
    Route::get('customer-cares/{id}/histories', [CustomerCareController::class, "getHistory"]);
    Route::get('customer-cares/{id}/orders', [CustomerCareController::class, "getOrder"]);
    Route::apiResource('customer-cares', CustomerCareController::class);
    
    Route::post("settings", [SettingController::class, "store"]);
    Route::get("overview", [CustomerCareController::class, "overview"]);

    Route::apiResource('loyalty-tiers', LoyaltyTierController::class);

    Route::put("api-key", function () {
        ApiKey::updateOrCreate(
            [
                "id" => 1
            ],[
                "api_key" => request()->api_key
            ]
        );

        return response()->json([
            "success" => true,
            "message" => "Cập nhật thành công"
        ]);
    });
    Route::get("api-key", function () {
        return response()->json([
            "success" => true,
            "data"    => ApiKey::select("id", "api_key")->first()
        ]);
    });

});

Route::prefix("webhook")->group(function() {
    Route::post("/pancake", [WebhookController::class, "recivePancake"]);
});