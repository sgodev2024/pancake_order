<?php

namespace App\Providers;

use App\Services\OrderSummaryService;
use App\Services\ReportDataVersion;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Invalidate after commit for both Eloquent and bulk/query-builder writes.
        // Cache-table writes do not match this list, so there is no recursion.
        DB::listen(function (QueryExecuted $query) {
            if (! preg_match('/^\s*(?:insert(?:\s+(?:or\s+\w+|ignore))?\s+into|update|delete\s+from)\s+[`"\[]?([a-z_]+)/i', $query->sql, $match)) {
                return;
            }
            $domain = match (strtolower($match[1])) {
                'orders' => 'orders',
                'customer_cares', 'customer_care_assignments', 'customer_assigneds', 'customer_assigned_by_customers', 'customer_assigned_by_staff' => 'cares',
                'users', 'roles', 'permissions', 'role_permissions', 'shop_users', 'shop_user', 'shops', 'provinces' => 'users',
                'customers', 'loyalty_tiers' => 'customers',
                default => null,
            };
            if ($domain !== null) {
                if ($domain === 'orders') {
                    OrderSummaryService::sourceWritten();
                }
                ReportDataVersion::afterCommit($domain);
            }
        });
        Passport::tokensCan([
            'change-password' => 'Chỉ được phép đổi mật khẩu lần đầu',
            'full-access' => 'Toàn quyền truy cập',
        ]);
        // Đăng ký các route cần thiết cho Passport
        // Bạn có thể tùy chỉnh thời gian hết hạn của token ở đây
        Passport::tokensExpireIn(now()->addDays(15));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        // THÊM DÒNG NÀY: Dành riêng cho Personal Access Token (hàm $user->createToken)
        Passport::personalAccessTokensExpireIn(now()->addDays(15));
    }
}
