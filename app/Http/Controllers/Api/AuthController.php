<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
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

            // 3. Tạo Token (Đây là lúc Passport vào cuộc)
            // 'Personal Access Token' là tên định danh cho token, bạn đặt là gì cũng được
            $token = $user->createToken('PancakeManagementToken')->accessToken;

            return response()->json([
                'success' => true,
                'message' => 'Đăng nhập thành công',
                'access_token' => $token,
                'token_type' => 'Bearer',
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
        $user = $request->user();

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
}
