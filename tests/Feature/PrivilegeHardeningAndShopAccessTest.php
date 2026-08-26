<?php

namespace Tests\Feature;

use App\Models\PermissionGroup;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\ShopAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PrivilegeHardeningAndShopAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'privilege_hardening_testing',
            'database.connections.privilege_hardening_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('privilege_hardening_testing');
        DB::setDefaultConnection('privilege_hardening_testing');
        $this->createSchema();
    }

    public function test_central_shop_scope_matches_explicit_memberships_for_all_role_types(): void
    {
        foreach ([11, 12, 15, 35, 37] as $shopId) {
            $this->createShop($shopId);
        }

        $admin = $this->createUser('admin', 'Admin A');
        $managerCskh = $this->createUser('manager-cskh', 'Manager CSKH M1');
        $managerSale = $this->createUser('manager-sale', 'Manager Sale M2');
        $staffCskh = $this->createUser('staff-cskh', 'Staff CSKH S1');
        $staffSale = $this->createUser('staff-sale', 'Staff Sale S2');
        $zeroShopManager = $this->createUser('manager', 'Zero Shop Manager M0');

        $this->attachShops($managerCskh, [11], false);
        $this->attachShops($managerSale, [35, 37], false);
        $this->attachShops($staffCskh, [11], true);
        $this->attachShops($staffSale, [35], false);

        $access = new ShopAccessService;

        $this->assertTrue($access->isGlobal($admin));
        $this->assertSame([], $access->ids($admin)->all());
        $this->assertSame([11], $access->ids($managerCskh)->all());
        $this->assertSame([35, 37], $access->ids($managerSale)->sort()->values()->all());
        $this->assertSame([11], $access->ids($staffCskh)->all());
        $this->assertSame([35], $access->ids($staffSale)->all());
        $this->assertSame([], $access->ids($zeroShopManager)->all());
        $this->assertFalse($access->isGlobal($zeroShopManager));
    }

    public function test_shop_access_checks_fail_closed_and_compare_local_shop_ids(): void
    {
        $shop11 = $this->createShop(11, 'external-shop-999');
        $shop12 = $this->createShop(12, '11');
        $manager = $this->createUser('manager-cskh', 'Manager M1');
        $admin = $this->createUser('admin', 'Admin A');
        $this->attachShops($manager, [11]);

        $access = new ShopAccessService;

        $this->assertTrue($access->canAccessShop($manager, 11));
        $this->assertTrue($access->canAccessShop($manager, $shop11));
        $this->assertFalse($access->canAccessShop($manager, $shop12));
        $this->assertTrue($access->canAccessShop($admin, $shop12));
        $this->assertNull($access->authorizeRequestedShopId($manager, null));
        $this->assertSame(11, $access->authorizeRequestedShopId($manager, 11));

        $this->expectException(AuthorizationException::class);
        $access->authorizeRequestedShopId($manager, 12);
    }

    public function test_profile_update_rejects_role_escalation_without_partial_update(): void
    {
        $shop11 = $this->createShop(11);
        $manager = $this->createUser('manager-cskh', 'Original Name');
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $this->attachShops($manager, [$shop11->id]);

        $this->actingAs($manager, 'api')
            ->putJson('/api/v1/me/update', [
                'name' => 'Partially Updated Name',
                'role_id' => $adminRole->id,
            ])
            ->assertForbidden();

        $this->assertSame('Original Name', $manager->fresh()->name);
        $this->assertSame('manager-cskh', $manager->fresh()->role->slug);
        $this->assertSame([11], $manager->fresh()->shops()->pluck('shops.id')->all());
    }

    public function test_profile_update_rejects_shop_escalation_and_combined_escalation(): void
    {
        $this->createShop(11);
        $this->createShop(12);
        $manager = $this->createUser('manager-cskh', 'Manager M1');
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $this->attachShops($manager, [11]);

        $this->actingAs($manager, 'api')
            ->putJson('/api/v1/me/update', ['shop_ids' => [11, 12]])
            ->assertForbidden();
        $this->actingAs($manager, 'api')
            ->putJson('/api/v1/me/update', [
                'role_id' => $adminRole->id,
                'shop_ids' => [12],
            ])
            ->assertForbidden();

        $manager->refresh();
        $this->assertSame('manager-cskh', $manager->role->slug);
        $this->assertSame([11], $manager->shops()->pluck('shops.id')->all());
    }

    public function test_legitimate_profile_field_still_updates_without_changing_memberships(): void
    {
        $this->createShop(11);
        $manager = $this->createUser('manager-cskh', 'Old Name');
        $this->attachShops($manager, [11]);

        $this->actingAs($manager, 'api')
            ->putJson('/api/v1/me/update', ['name' => 'New Name'])
            ->assertOk();

        $manager->refresh();
        $this->assertSame('New Name', $manager->name);
        $this->assertSame([11], $manager->shops()->pluck('shops.id')->all());
    }

    public function test_public_registration_cannot_assign_a_role(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Attacker',
            'email' => 'attacker@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role_id' => $adminRole->id,
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.test']);
    }

    public function test_legacy_public_test_route_is_not_registered(): void
    {
        $this->getJson('/api/test')->assertNotFound();
    }

    public function test_user_role_and_shop_assignment_endpoints_are_admin_only(): void
    {
        $this->createShop(11);
        $this->createShop(12);
        $manager = $this->createUser('manager-cskh', 'Manager M1');
        $target = $this->createUser('staff-cskh', 'Staff B');
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $this->attachShops($target, [11]);

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/users', [
                'name' => 'Injected Admin',
                'email' => 'injected-admin@example.test',
                'password' => 'password',
                'phone_number' => '0123456789',
                'role_id' => $adminRole->id,
                'shop_ids' => [12],
            ])
            ->assertForbidden();
        $this->actingAs($manager, 'api')
            ->putJson("/api/v1/users/{$target->id}", [
                'role_id' => $adminRole->id,
                'shop_ids' => [12],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'injected-admin@example.test']);
        $target->refresh();
        $this->assertSame('staff-cskh', $target->role->slug);
        $this->assertSame([11], $target->shops()->pluck('shops.id')->all());
    }

    public function test_non_admin_cannot_delete_users_through_the_staff_permission_path(): void
    {
        $manager = $this->createUser('manager-cskh', 'Manager M1');
        $target = $this->createUser('staff-cskh', 'Staff B');

        $this->actingAs($manager, 'api')
            ->deleteJson("/api/v1/users/{$target->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_non_admin_cannot_add_themselves_or_another_user_to_a_shop(): void
    {
        $this->createShop(11);
        $this->createShop(12);
        $manager = $this->createUser('manager-cskh', 'Manager M1');
        $staff = $this->createUser('staff-cskh', 'Staff B');
        $this->attachShops($manager, [11]);

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/shops/12/users', ['email' => $manager->email])
            ->assertForbidden();
        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/shops/12/users', ['email' => $staff->email])
            ->assertForbidden();

        $this->assertDatabaseMissing('shop_users', ['shop_id' => 12, 'user_id' => $manager->id]);
        $this->assertDatabaseMissing('shop_users', ['shop_id' => 12, 'user_id' => $staff->id]);
    }

    public function test_non_admin_cannot_set_is_manager_or_mutate_any_membership(): void
    {
        $this->createShop(11);
        $user = $this->createUser('manager-sale', 'Manager Sale');
        $target = $this->createUser('staff-sale', 'Staff Sale');
        $this->attachShops($target, [11], false);

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/shops/11/users/{$target->id}", ['is_manager' => true])
            ->assertForbidden();

        $this->assertDatabaseHas('shop_users', [
            'shop_id' => 11,
            'user_id' => $target->id,
            'is_manager' => false,
        ]);
    }

    public function test_admin_can_preserve_existing_shop_membership_management_behavior(): void
    {
        $this->createShop(11);
        $admin = $this->createUser('admin', 'Admin A');
        $staff = $this->createUser('staff-cskh', 'Staff B');

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/shops/11/users', [
                'email' => $staff->email,
                'is_manager' => false,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('shop_users', [
            'shop_id' => 11,
            'user_id' => $staff->id,
            'is_manager' => false,
        ]);
    }

    public function test_non_admin_roles_are_forbidden_from_role_and_permission_administration(): void
    {
        $endpoints = [
            ['getJson', '/api/v1/roles', []],
            ['postJson', '/api/v1/roles', ['name' => 'Injected', 'code' => 'injected']],
            ['getJson', '/api/v1/permission-groups', []],
            ['postJson', '/api/v1/permission-groups', ['name' => 'Injected']],
            ['getJson', '/api/v1/permissions', []],
            ['postJson', '/api/v1/permissions', []],
            ['getJson', '/api/v1/role-permissions/1', []],
            ['postJson', '/api/v1/role-permissions', []],
        ];

        foreach (['manager-cskh', 'manager-sale', 'staff-cskh'] as $slug) {
            $actor = $this->createUser($slug, $slug);
            foreach ($endpoints as [$method, $uri, $payload]) {
                $this->actingAs($actor, 'api')->{$method}($uri, $payload)->assertForbidden();
            }
        }
    }

    public function test_admin_can_use_existing_role_and_permission_administration_actions(): void
    {
        $admin = $this->createUser('admin', 'Admin A');
        $group = PermissionGroup::create(['name' => 'Existing Group']);
        $role = Role::create(['name' => 'Existing Role', 'slug' => 'existing-role']);

        $this->actingAs($admin, 'api')->getJson('/api/v1/roles')->assertOk();
        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/roles', ['name' => 'New Role', 'code' => 'new-role'])
            ->assertCreated();
        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/permission-groups', ['name' => 'New Group'])
            ->assertCreated();
        $permissionResponse = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/permissions', [
                'permission_group_id' => $group->id,
                'name' => 'New Permission',
                'slug' => 'new-permission',
            ])
            ->assertCreated();
        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/role-permissions', [
                'role_id' => $role->id,
                'permission_ids' => [$permissionResponse->json('data.id')],
            ])
            ->assertOk();
    }

    public function test_api_key_management_is_admin_only(): void
    {
        $manager = $this->createUser('manager-cskh', 'Manager M1');
        $admin = $this->createUser('admin', 'Admin A');

        $this->actingAs($manager, 'api')->getJson('/api/v1/api-key')->assertForbidden();
        $this->actingAs($manager, 'api')
            ->putJson('/api/v1/api-key', ['api_key' => 'not-written'])
            ->assertForbidden();
        $this->assertDatabaseCount('api_keys', 0);

        $this->actingAs($admin, 'api')
            ->putJson('/api/v1/api-key', ['api_key' => 'admin-managed-secret'])
            ->assertOk();
        $this->actingAs($admin, 'api')->getJson('/api/v1/api-key')->assertOk();
        $this->assertDatabaseCount('api_keys', 1);
    }

    public function test_employee_import_membership_paths_are_admin_only(): void
    {
        $shop = $this->createShop(11);
        $manager = $this->createUser('manager-cskh', 'Manager M1');
        $admin = $this->createUser('admin', 'Admin A');

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/shops/{$shop->id}/update-employee-from-pancake")
            ->assertForbidden();
        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/shops/{$shop->id}/get-data-pancake", ['type' => 'employee'])
            ->assertForbidden();
        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/shops', ['api_key' => 'manager-key'])
            ->assertForbidden();

        Http::fake(['*' => Http::response(['data' => []])]);
        Queue::fake();
        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/shops/{$shop->id}/update-employee-from-pancake")
            ->assertOk();
        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/shops/{$shop->id}/get-data-pancake", ['type' => 'employee'])
            ->assertOk();
    }

    private function createSchema(): void
    {
        Schema::create('permission_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('permission_group_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('pancake_user_id')->nullable();
            $table->string('fb_id')->nullable();
            $table->string('phone_number')->nullable();
            $table->text('pancake_full_data')->nullable();
            $table->string('avatar_url')->nullable();
            $table->boolean('is_first_login')->default(true);
            $table->timestamps();
        });
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('pancake_shop_id');
            $table->string('name');
            $table->string('api_key');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('shop_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_manager')->default(false);
            $table->unique(['user_id', 'shop_id']);
            $table->timestamps();
        });
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('api_key')->nullable();
            $table->timestamps();
        });
    }

    private function createUser(string $roleSlug, string $name): User
    {
        $role = Role::firstOrCreate(
            ['slug' => $roleSlug],
            ['name' => $roleSlug]
        );
        $nextId = ((int) User::max('id')) + 1;

        return User::create([
            'role_id' => $role->id,
            'name' => $name,
            'email' => "security-{$nextId}@example.test",
            'password' => 'unused-password',
            'pancake_user_id' => "PANCAKE-{$nextId}",
        ]);
    }

    private function createShop(int $id, ?string $pancakeShopId = null): Shop
    {
        DB::table('shops')->insert([
            'id' => $id,
            'pancake_shop_id' => $pancakeShopId ?? "external-{$id}",
            'name' => "Shop {$id}",
            'api_key' => "shop-key-{$id}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Shop::findOrFail($id);
    }

    private function attachShops(User $user, array $shopIds, bool $isManager = false): void
    {
        foreach ($shopIds as $shopId) {
            $user->shops()->attach($shopId, ['is_manager' => $isManager]);
        }
    }
}
