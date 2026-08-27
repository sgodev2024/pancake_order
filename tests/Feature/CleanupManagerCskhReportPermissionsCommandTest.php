<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CleanupManagerCskhReportPermissionsCommandTest extends TestCase
{
    private const REPORT_SLUGS = [
        'revenue',
        'region-report',
        'loyalty-report',
        'customer-pendding-upgrade',
        'customer-care',
    ];

    private const CSKH_SLUGS = [
        'asign-cskh',
        'accept-schedule',
        'reject-cskh',
        'delete-cskh',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'cleanup_manager_cskh_report_permissions_testing',
            'database.connections.cleanup_manager_cskh_report_permissions_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('cleanup_manager_cskh_report_permissions_testing');
        DB::setDefaultConnection('cleanup_manager_cskh_report_permissions_testing');
        $this->createSchema();
    }

    public function test_preview_is_read_only_and_reports_all_five_initial_links(): void
    {
        $fixture = $this->seedFixture();

        $permissionIds = Permission::query()
            ->whereIn('slug', self::REPORT_SLUGS)
            ->pluck('id', 'slug');
        $expectedRows = array_map(fn (string $slug) => [
            $fixture['manager']->id,
            $slug,
            $permissionIds->get($slug),
            'LINKED',
            'WOULD_REMOVE',
        ], self::REPORT_SLUGS);

        $this->assertSame(5, $this->managerTargetLinkCount());

        $this->artisan('auth:cleanup-manager-cskh-report-permissions')
            ->expectsOutputToContain('PREVIEW ONLY')
            ->expectsTable(
                ['Role ID', 'Permission Slug', 'Permission ID', 'Link Status', 'Action'],
                $expectedRows
            )
            ->expectsOutputToContain('Would remove: 5')
            ->assertSuccessful();

        $this->assertSame(5, $this->managerTargetLinkCount());
        $this->assertSame(10, DB::table('permissions')->count());
    }

    public function test_execute_removes_only_five_target_links_and_is_idempotent(): void
    {
        $fixture = $this->seedFixture();
        $initialRolePermissionCount = DB::table('role_permissions')->count();
        $initialPermissionIds = DB::table('permissions')->pluck('id')->sort()->values()->all();
        $otherRoleLinks = DB::table('role_permissions')
            ->where('role_id', $fixture['other_role']->id)
            ->pluck('permission_id')
            ->sort()
            ->values()
            ->all();

        $this->artisan('auth:cleanup-manager-cskh-report-permissions', ['--execute' => true])
            ->expectsOutputToContain('EXECUTE MODE')
            ->expectsOutputToContain('Removed: 5')
            ->expectsOutputToContain('Remaining linked target rows: 0')
            ->assertSuccessful();

        $this->assertSame(0, $this->managerTargetLinkCount());
        $this->assertSame($initialRolePermissionCount - 5, DB::table('role_permissions')->count());
        $this->assertSame($initialPermissionIds, DB::table('permissions')->pluck('id')->sort()->values()->all());
        $this->assertDatabaseHas('roles', ['id' => $fixture['manager']->id, 'slug' => 'manager-cskh']);
        $this->assertDatabaseHas('roles', ['id' => $fixture['other_role']->id, 'slug' => 'other-role']);

        foreach (self::CSKH_SLUGS as $slug) {
            $permissionId = Permission::query()->where('slug', $slug)->value('id');

            $this->assertDatabaseHas('role_permissions', [
                'role_id' => $fixture['manager']->id,
                'permission_id' => $permissionId,
            ]);
        }

        $otherPermissionId = Permission::query()->where('slug', 'other-permission')->value('id');
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $fixture['manager']->id,
            'permission_id' => $otherPermissionId,
        ]);

        $this->assertSame(
            $otherRoleLinks,
            DB::table('role_permissions')
                ->where('role_id', $fixture['other_role']->id)
                ->pluck('permission_id')
                ->sort()
                ->values()
                ->all()
        );

        $this->artisan('auth:cleanup-manager-cskh-report-permissions', ['--execute' => true])
            ->expectsOutputToContain('Removed: 0')
            ->expectsOutputToContain('Remaining linked target rows: 0')
            ->assertSuccessful();

        $this->assertSame(0, $this->managerTargetLinkCount());
        $this->assertSame($initialRolePermissionCount - 5, DB::table('role_permissions')->count());
    }

    public function test_missing_role_fails_without_mutation(): void
    {
        $this->artisan('auth:cleanup-manager-cskh-report-permissions')
            ->expectsOutputToContain('Role not found: manager-cskh')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('role_permissions')->count());
        $this->assertSame(0, DB::table('permissions')->count());
    }

    public function test_missing_permission_is_reported_and_never_created(): void
    {
        $manager = Role::create(['name' => 'Quản lý CSKH', 'slug' => 'manager-cskh']);

        foreach (array_slice(self::REPORT_SLUGS, 0, 4) as $slug) {
            $permission = Permission::create(['name' => $slug, 'slug' => $slug]);
            DB::table('role_permissions')->insert([
                'role_id' => $manager->id,
                'permission_id' => $permission->id,
            ]);
        }

        $this->artisan('auth:cleanup-manager-cskh-report-permissions')
            ->expectsOutputToContain('NOT_FOUND')
            ->expectsOutputToContain('customer-care')
            ->expectsOutputToContain('Would remove: 4')
            ->assertSuccessful();

        $this->assertSame(4, DB::table('role_permissions')->count());
        $this->assertDatabaseMissing('permissions', ['slug' => 'customer-care']);
    }

    /**
     * @return array{manager: Role, other_role: Role}
     */
    private function seedFixture(): array
    {
        $manager = Role::create(['name' => 'Quản lý CSKH', 'slug' => 'manager-cskh']);
        $otherRole = Role::create(['name' => 'Other Role', 'slug' => 'other-role']);

        $permissions = [];
        foreach (array_merge(self::REPORT_SLUGS, self::CSKH_SLUGS) as $slug) {
            $permissions[$slug] = Permission::create(['name' => $slug, 'slug' => $slug]);
        }

        $otherPermission = Permission::create(['name' => 'Other permission', 'slug' => 'other-permission']);
        $this->link($manager->id, $otherPermission->id);

        foreach (array_merge(self::REPORT_SLUGS, self::CSKH_SLUGS) as $slug) {
            $this->link($manager->id, $permissions[$slug]->id);
        }

        foreach (self::REPORT_SLUGS as $slug) {
            $this->link($otherRole->id, $permissions[$slug]->id);
        }

        return ['manager' => $manager, 'other_role' => $otherRole];
    }

    private function link(int $roleId, int $permissionId): void
    {
        DB::table('role_permissions')->insert([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);
    }

    private function managerTargetLinkCount(): int
    {
        return (int) DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('roles.slug', 'manager-cskh')
            ->whereIn('permissions.slug', self::REPORT_SLUGS)
            ->count();
    }

    private function createSchema(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamps();
        });
    }
}
