<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $domains = ['companies', 'branches', 'customers', 'vehicles', 'reservations', 'rentals', 'documents', 'users', 'quotations'];
        $permissions = [];
        foreach ($domains as $domain) {
            foreach (['view', 'manage'] as $action) {
                $name = $domain.'.'.$action;
                Permission::findOrCreate($name, 'web');
                $permissions[] = $name;
            }
        }
        Role::findOrCreate('company-admin', 'web')->syncPermissions($permissions);
        Role::findOrCreate('operations', 'web')->syncPermissions(array_values(array_filter($permissions, fn ($name) => ! str_starts_with($name, 'users.') && ! in_array($name, ['companies.manage', 'branches.manage'], true))));
        Role::findOrCreate('viewer', 'web')->syncPermissions(array_values(array_filter($permissions, fn ($name) => str_ends_with($name, '.view') && ! str_starts_with($name, 'users.'))));
        Permission::findOrCreate('settings.manage', 'web');
        Permission::findOrCreate('quotations.approve_discount', 'web');
        Role::findOrCreate('company-admin', 'web')->givePermissionTo(['settings.manage', 'quotations.approve_discount']);
        Role::findOrCreate('finance', 'web')->syncPermissions(['companies.view', 'branches.view', 'customers.view', 'vehicles.view', 'reservations.view', 'rentals.view', 'documents.view', 'quotations.view', 'quotations.approve_discount']);
        Role::findOrCreate('driver', 'web')->syncPermissions(['companies.view', 'branches.view']);
    }
}
