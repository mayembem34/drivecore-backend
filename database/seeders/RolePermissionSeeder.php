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
        $financePermissions = ['invoices.view', 'invoices.manage', 'payments.manage', 'expenses.view', 'expenses.manage', 'gl.view'];
        foreach ($financePermissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        foreach (['company-admin', 'finance'] as $role) {
            Role::findOrCreate($role, 'web')->givePermissionTo($financePermissions);
        }
        Role::findOrCreate('viewer', 'web')->givePermissionTo(['invoices.view', 'expenses.view']);
        foreach (['work_orders', 'parts', 'suppliers', 'purchase_orders'] as $domain) {
            foreach (['view', 'manage'] as $action) {
                Permission::findOrCreate($domain.'.'.$action, 'web');
                Role::findOrCreate('company-admin', 'web')->givePermissionTo($domain.'.'.$action);
                Role::findOrCreate('operations', 'web')->givePermissionTo($domain.'.'.$action);
                if ($action === 'view') {
                    foreach (['finance', 'viewer'] as $role) {
                        Role::findOrCreate($role, 'web')->givePermissionTo($domain.'.view');
                    }
                }
            }
        }
        Permission::findOrCreate('purchase_orders.approve', 'web');
        foreach (['company-admin', 'finance'] as $role) {
            Role::findOrCreate($role, 'web')->givePermissionTo('purchase_orders.approve');
        }
        Role::findOrCreate('driver', 'web')->syncPermissions(['companies.view', 'branches.view']);
        foreach (['messages.view', 'messages.send', 'reports.view', 'audit.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate('company-admin', 'web')->givePermissionTo(['messages.view', 'messages.send', 'reports.view', 'audit.view']);
        foreach (['operations', 'finance'] as $role) {
            Role::findOrCreate($role, 'web')->givePermissionTo(['messages.view', 'messages.send', 'reports.view']);
        }
        Role::findOrCreate('viewer', 'web')->givePermissionTo(['messages.view', 'reports.view']);
        Role::findOrCreate('driver', 'web')->givePermissionTo(['messages.view', 'messages.send']);
        foreach (['rate_cards', 'fuel', 'inspections'] as $domain) {
            foreach (['view', 'manage'] as $action) {
                $permission = $domain.'.'.$action;
                Permission::findOrCreate($permission, 'web');
                foreach (['company-admin', 'operations'] as $role) {
                    Role::findOrCreate($role, 'web')->givePermissionTo($permission);
                } if ($action === 'view') {
                    foreach (['finance', 'viewer'] as $role) {
                        Role::findOrCreate($role, 'web')->givePermissionTo($permission);
                    }
                }
            }
        }
        foreach (['credit.view', 'credit.manage', 'approvals.view', 'approvals.request', 'approvals.decide'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            foreach (['company-admin', 'finance'] as $role) {
                Role::findOrCreate($role, 'web')->givePermissionTo($permission);
            }
        }
        Role::findOrCreate('operations', 'web')->givePermissionTo(['approvals.view', 'approvals.request']);
        foreach (['ap.view', 'ap.manage', 'banking.view', 'banking.manage', 'assets.view', 'assets.manage', 'statements.view', 'credit_notes.request'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            foreach (['company-admin', 'finance'] as $role) {
                Role::findOrCreate($role, 'web')->givePermissionTo($permission);
            }
        }

        foreach (['leads.view', 'leads.manage', 'roles.view', 'users.manage_non_privileged', 'work_orders.view_assigned', 'work_orders.manage_assigned'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $read = Permission::where('guard_name', 'web')->where('name', 'like', '%.view')->pluck('name')->all();
        $oversight = array_merge($read, ['approvals.decide', 'approvals.request', 'messages.send', 'quotations.approve_discount', 'purchase_orders.approve']);
        foreach (['ceo', 'general-manager'] as $role) {
            Role::findOrCreate($role, 'web')->syncPermissions($oversight);
        }
        Role::findOrCreate('system-administrator', 'web')->syncPermissions(Permission::where('guard_name', 'web')->pluck('name')->all());
        Role::findOrCreate('company-admin', 'web')->givePermissionTo(['leads.view', 'leads.manage', 'roles.view']);
        Role::findOrCreate('business-accounts-manager', 'web')->syncPermissions(['companies.view', 'branches.view', 'leads.view', 'leads.manage', 'customers.view', 'customers.manage', 'rate_cards.view', 'rate_cards.manage', 'quotations.view', 'quotations.manage', 'reservations.view', 'reservations.manage', 'rentals.view', 'documents.view', 'messages.view', 'messages.send', 'reports.view', 'approvals.view', 'approvals.request']);
        Role::findOrCreate('operations-manager', 'web')->syncPermissions(['companies.view', 'branches.view', 'customers.view', 'vehicles.view', 'vehicles.manage', 'reservations.view', 'reservations.manage', 'rentals.view', 'rentals.manage', 'documents.view', 'documents.manage', 'inspections.view', 'inspections.manage', 'fuel.view', 'fuel.manage', 'messages.view', 'messages.send', 'reports.view', 'approvals.view', 'approvals.request']);
        Role::findOrCreate('maintenance-manager', 'web')->syncPermissions(['companies.view', 'branches.view', 'vehicles.view', 'work_orders.view', 'work_orders.manage', 'parts.view', 'parts.manage', 'suppliers.view', 'suppliers.manage', 'purchase_orders.view', 'purchase_orders.manage', 'fuel.view', 'fuel.manage', 'messages.view', 'messages.send', 'reports.view', 'approvals.view', 'approvals.request']);
        Role::findOrCreate('finance-manager', 'web')->syncPermissions(Role::findByName('finance', 'web')->permissions->pluck('name')->all());
        Role::findOrCreate('credit-control-manager', 'web')->syncPermissions(['companies.view', 'branches.view', 'customers.view', 'invoices.view', 'credit.view', 'credit.manage', 'credit_notes.request', 'reports.view', 'messages.view', 'messages.send', 'approvals.view', 'approvals.request']);
        Role::findOrCreate('hr-admin-manager', 'web')->syncPermissions(['companies.view', 'branches.view', 'users.view', 'users.manage_non_privileged', 'roles.view', 'documents.view', 'documents.manage', 'messages.view', 'messages.send']);
        Role::findOrCreate('mechanic-technician', 'web')->syncPermissions(['work_orders.view_assigned', 'work_orders.manage_assigned', 'parts.view']);
    }
}
