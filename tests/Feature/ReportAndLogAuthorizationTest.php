<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportAndLogAuthorizationTest extends TestCase
{
    #[DataProvider('nonAdminRoleProvider')]
    public function test_non_admins_receive_forbidden_for_report_and_log_endpoints(string $roleSlug): void
    {
        $actor = $this->userWithRole($roleSlug);

        foreach ([
            '/api/v1/activity-logs',
            '/api/v1/users/all-user?page_name=activity_log',
            '/api/v1/provinces/report',
            '/api/v1/users?page_name=report_page',
        ] as $endpoint) {
            $this->actingAs($actor, 'api')
                ->getJson($endpoint)
                ->assertStatus(403)
                ->assertExactJson([
                    'success' => false,
                    'message' => 'Bạn không có quyền.',
                ]);
        }
    }

    public static function nonAdminRoleProvider(): array
    {
        return [
            'manager' => ['manager'],
            'manager-sale' => ['manager-sale'],
            'manager-cskh' => ['manager-cskh'],
            'employee' => ['employee'],
            'staff-sale' => ['staff-sale'],
            'staff-cskh' => ['staff-cskh'],
            'unknown role' => ['unknown'],
        ];
    }

    public function test_missing_role_is_forbidden_for_sensitive_endpoints(): void
    {
        $actor = new User();

        foreach ([
            '/api/v1/activity-logs',
            '/api/v1/provinces/report',
        ] as $endpoint) {
            $this->actingAs($actor, 'api')
                ->getJson($endpoint)
                ->assertStatus(403);
        }
    }

    public function test_unauthenticated_sensitive_request_remains_unauthorized(): void
    {
        $this->getJson('/api/v1/activity-logs')
            ->assertStatus(401);
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = new User();
        $role = new Role(['name' => $roleSlug, 'slug' => $roleSlug]);
        $role->setRelation('permissions', collect([
            ['slug' => 'view-action'],
            ['slug' => 'revenue'],
            ['slug' => 'region-report'],
        ]));

        return $user->setRelation('role', $role);
    }
}
