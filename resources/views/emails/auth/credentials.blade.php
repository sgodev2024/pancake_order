<!DOCTYPE html>
<html>
<head>
    <title>Thông tin đăng nhập</title>
</head>
<body>
    <h2>Xin chào {{ $user->name ?? 'bạn' }},</h2>
    <p>Tài khoản hệ thống của bạn đã được tạo thành công. Dưới đây là thông tin đăng nhập:</p>
    
    <ul>
        <li><strong>Email:</strong> {{ $user->email }}</li>
        <li><strong>Mật khẩu:</strong> {{ $rawPassword }}</li>
    </ul>

    <p>Vui lòng đăng nhập và đổi mật khẩu trong lần đầu sử dụng để bảo mật tài khoản.</p>
    <p>Trân trọng!</p>
</body>
</html>