<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // 1. Kiểm tra đăng nhập
        if (Auth::attempt($credentials)) {
            // 2. Lấy đối tượng user
            $user = Auth::user();

            // Đăng nhập lần đầu → token scope hạn chế
            if ($user->is_first_login) {
                $token = $user->createToken('PancakeManagementToken', ['change-password']);
                $expiresAt = $token->token->expires_at->toDateTimeString();

                return response()->json([
                    'success'                 => true,
                    'require_password_change' => true,
                    'message'                 => 'Vui lòng đổi mật khẩu trước khi tiếp tục',
                    'access_token'            => $token->accessToken,
                    'token_type'              => 'Bearer',
                    'expires_at'              => $expiresAt,
                    'user' => [
                        'id'    => $user->id,
                        'name'  => $user->name,
                        'email' => $user->email,
                    ]
                ], 200);
            }

            // 3. Tạo Token (Đây là lúc Passport vào cuộc)
            // 'Personal Access Token' là tên định danh cho token, bạn đặt là gì cũng được
            $token = $user->createToken('PancakeManagementToken', ['full-access']);
            $expiresAt = $token->token->expires_at->toDateTimeString();

            return response()->json([
                'success' => true,
                'require_password_change' => false,
                'message' => 'Đăng nhập thành công',
                'access_token' => $token->accessToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ]
            ], 200);
        }

        // Trả về lỗi nếu sai thông tin
        return response()->json([
            'success' => false,
            'message' => 'Email hoặc mật khẩu không chính xác',
        ], 401);
    }

    /**
     * Đăng ký tài khoản mới
     */
    public function register(Request $request)
    {
        // 1. Kiểm tra dữ liệu đầu vào (Validation)
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed', // Cần gửi password_confirmation từ ReactJS
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ',
                'errors'  => $validator->errors()
            ], 422);
        }

        // 2. Tạo User mới trong Database
        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'role_id'  => $request->role_id ?? NULL,
            'password' => Hash::make($request->password), // Bắt buộc phải Hash
        ]);

        // 3. Tạo Token ngay lập tức (Để User đăng nhập luôn)
        $tokenResult = $user->createToken('PancakeManagementToken');
        $token = $tokenResult->accessToken;

        // 4. Phản hồi kết quả
        return response()->json([
            'success' => true,
            'message' => 'Đăng ký tài khoản thành công',
            'data' => [
                'user'         => $user,
                'access_token' => $token,
                'token_type'   => 'Bearer'
            ]
        ], 201); // 201: Created
    }

    /**
     * Lấy thông tin tài khoản đang đăng nhập
     */
    public function profile(Request $request)
    {
        // Vì route này nằm trong middleware 'auth:api', 
        // $request->user() sẽ luôn chứa thông tin user tương ứng với token gửi lên.
        $user = $request->user()->load('role:id,name', 'role.permissions:id,name,slug');

        return response()->json([
            'success' => true,
            'message' => 'Lấy thông tin tài khoản thành công',
            'data'    => $user
        ], 200);
    }

    /**
     * Đăng xuất và hủy Token
     */
    public function logout(Request $request)
    {
        // Lấy token hiện tại mà user đang sử dụng để gọi API này
        $token = $request->user()->token();

        // Thu hồi token (đánh dấu revoked = 1 trong bảng oauth_access_tokens)
        $token->revoke();

        return response()->json([
            'success' => true,
            'message' => 'Đăng xuất thành công, token đã được vô hiệu hóa'
        ], 200);
    }

    public function changeFirstPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|confirmed|min:6',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        $inputs = $request->only(
            "current_password",
            "new_password"
        );
        $user = $request->user();
        // Kiểm tra token có đúng scope không
        if (!$user->tokenCan('change-password') && !$user->tokenCan('full-access')) {
            return response()->json([
                'success' => false,
                'message' => 'Không có quyền thực hiện.'
            ], 403);
        }

        // Kiểm tra mật khẩu hiện tại
        if (!Hash::check($inputs["current_password"], $user->password)) {
            return response()->json([
                'message' => 'Mật khẩu hiện tại không đúng.',
            ], 422);
        }

        // Không cho đặt mật khẩu giống cũ
        if (Hash::check($inputs["new_password"], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Mật khẩu mới không được trùng mật khẩu cũ.',
            ], 422);
        }

        // Cập nhật mật khẩu và tắt cờ first login
        $user->update([
            'password'       => Hash::make($inputs["new_password"]),
            'is_first_login' => false,
        ]);

        // Thu hồi token cũ (scope hạn chế), cấp token mới đầy đủ
        $user->tokens()->delete();
        $token = $user->createToken('auth_token', ['full-access'])->accessToken;

        return response()->json([
            'success'      => true,
            'message'      => 'Đổi mật khẩu thành công.',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user->only('id', 'name', 'email'),
        ], 200);
    }
}
