<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PermissionGroupController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RolePermissionController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\ShopCustomerController;
use App\Http\Controllers\Api\ShopOrderController;
use App\Http\Controllers\Api\ShopUserController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


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

// --- Nhóm các Route Private (Yêu cầu Passport Token) ---
Route::middleware('auth:api')->prefix('v1')->group(function () {
    
    // Nhóm tài khoản (Profile)
    Route::prefix('me')->group(function () {
        // Lấy thông tin tài khoản đang đăng nhập
        Route::get('/', [AuthController::class, 'profile']);
        
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
    
    Route::apiResource('shops', ShopController::class);
    Route::apiResource('shops.customers', ShopCustomerController::class);
    Route::apiResource('shops.orders', ShopOrderController::class);
    Route::apiResource('shops.users', ShopUserController::class);

    Route::apiResource("orders", OrderController::class);
    Route::apiResource("customers", CustomerController::class);

    Route::apiResource('users', UserController::class);
});

Route::prefix("webhook")->group(function() {
    Route::post("/pancake", [WebhookController::class, "recivePancake"]);
});