<?php

namespace Tests\Feature\Inventory;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InventoryPageTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://app.meditech.local/inventory';

    private function userWithPermissions(array $codes, bool $active = true): User
    {
        $user = User::factory()->create(['is_active' => $active]);
        $role = Role::query()->create([
            'role_name' => 'Inventory tester',
            'description' => 'Role khusus pengujian Inventory.',
            'is_active' => true,
            'is_super_admin' => false,
            'is_system_role' => false,
        ]);
        $user->roles()->attach($role->role_id, ['assigned_at' => now()]);

        foreach ($codes as $code) {
            [$module, $operation] = explode('.', $code);
            $permission = Permission::query()->create([
                'permission_code' => $code,
                'module_code' => $module,
                'operation_code' => $operation,
                'description' => 'Izin pengujian Inventory.',
                'is_active' => true,
                'is_assignable' => true,
                'is_destructive_operation' => false,
            ]);
            $role->permissions()->attach($permission->permission_id, ['granted_at' => now()]);
        }

        return $user;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(self::URL)->assertRedirect('http://app.meditech.local/login');
    }

    public function test_permission_is_required(): void
    {
        $this->actingAs($this->userWithPermissions([]))->get(self::URL)->assertForbidden();
    }

    public function test_inactive_user_cannot_access_inventory(): void
    {
        $this->actingAs($this->userWithPermissions(['inventory.read'], false))
            ->get(self::URL)->assertRedirect();
    }

    public function test_reader_sees_inventory_without_transaction_forms_or_export_actions(): void
    {
        $this->actingAs($this->userWithPermissions(['inventory.read']))
            ->get(self::URL)->assertOk()
            ->assertDontSee('Preview UI / data demo.')
            ->assertDontSee('Pratinjau')
            ->assertSee('inventory-stock-rows', false)
            ->assertSee('movements-dialog', false)
            ->assertDontSee('id="goods-in-form"', false)
            ->assertDontSee('id="goods-out-form"', false)
            ->assertDontSee('id="goods-out-confirm-dialog"', false)
            ->assertDontSee('data-export-table=', false)
            ->assertDontSee('data-print-table=', false);
    }

    public function test_transaction_forms_follow_their_own_permissions(): void
    {
        $user = $this->userWithPermissions(['inventory.read', 'inventory.create']);
        $this->actingAs($user)->get(self::URL)->assertOk()
            ->assertSee('id="goods-in-form"', false)
            ->assertDontSee('id="goods-out-form"', false);
    }

    public function test_editor_has_dispatch_form_and_confirmation_without_receipt_permission(): void
    {
        $this->actingAs($this->userWithPermissions(['inventory.read', 'inventory.update']))
            ->get(self::URL)->assertOk()
            ->assertSee('id="goods-out-form"', false)
            ->assertSee('id="goods-out-confirm-dialog"', false)
            ->assertSee('Periksa &amp; konfirmasi', false)
            ->assertSee('Konfirmasi &amp; keluarkan stok', false)
            ->assertSee('method="dialog"', false)
            ->assertDontSee('id="goods-in-form"', false);
    }

    public function test_slicing_does_not_register_stock_writing_endpoints(): void
    {
        $this->actingAs($this->userWithPermissions(['inventory.read', 'inventory.create', 'inventory.update']))
            ->post(self::URL)->assertStatus(405);

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('goods_receipts', 0);
        $this->assertDatabaseCount('goods_issues', 0);
    }

    public function test_all_inventory_tables_have_consistent_pagination_and_no_inventory_writes(): void
    {
        $response = $this->actingAs($this->userWithPermissions(['inventory.read', 'inventory.create', 'inventory.update']))
            ->get(self::URL)->assertOk();

        foreach (['inventory-stock', 'inventory-movement', 'inventory-issue', 'inventory-confirm'] as $prefix) {
            foreach (['size', 'prev', 'next', 'count', 'page'] as $control) {
                $response->assertSee('id="'.$prefix.'-'.$control.'"', false);
            }
        }

        $response->assertSee('ui-page-title', false)
            ->assertSee('inventory-issue-row', false)
            ->assertSee('inventory-empty-row', false)
            ->assertSee('Konfirmasi penerimaan')
            ->assertSee('href="#i-plus"', false)
            ->assertSee('href="#inventory-icon-out"', false)
            ->assertDontSee('Pratinjau')
            ->assertDontSee('data demo')
            ->assertDontSee('ui-headline-1');

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('goods_receipts', 0);
        $this->assertDatabaseCount('goods_issues', 0);
    }

    public function test_export_actions_follow_the_existing_export_permission(): void
    {
        $response = $this->actingAs($this->userWithPermissions(['inventory.read', 'inventory.export']))
            ->get(self::URL)->assertOk();

        foreach (['inventory-stock-rows', 'movement-rows'] as $target) {
            $response->assertSee('data-export-table="'.$target.'"', false)
                ->assertSee('data-print-table="'.$target.'"', false);
        }
    }

    public function test_inventory_route_is_available_outside_local_and_testing(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $routes = new RouteCollection;
        Route::setRoutes($routes);
        Route::middleware(['auth', 'active'])->group(base_path('routes/inventory.php'));
        $routes->refreshNameLookups();

        $route = $routes->getByName('inventory.index');
        $this->assertNotNull($route);
        $this->assertSame('inventory', $route->uri());
        $this->assertContains('permission:inventory.read', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('active', $route->gatherMiddleware());
    }
}
