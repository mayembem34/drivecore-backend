<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $domains = ['companies', 'branches', 'customers', 'vehicles', 'reservations', 'rentals', 'documents', 'users'];
        $permissions = [];
        foreach ($domains as $domain) {
            foreach (['view', 'manage'] as $action) {
                $name = $domain.'.'.$action;
                Permission::findOrCreate($name, 'web');
                $permissions[] = $name;
            }
        }
        Role::findOrCreate('company-admin', 'web')->syncPermissions($permissions);
        Role::findOrCreate('operations', 'web')->syncPermissions(array_values(array_filter($permissions, fn ($name) => ! str_starts_with($name, 'users.') && ! str_starts_with($name, 'companies.') && ! str_starts_with($name, 'branches.'))));
        Role::findOrCreate('viewer', 'web')->syncPermissions(array_values(array_filter($permissions, fn ($name) => str_ends_with($name, '.view') && ! str_starts_with($name, 'users.'))));
        Role::findOrCreate('driver', 'web')->syncPermissions([]);
    }
}
