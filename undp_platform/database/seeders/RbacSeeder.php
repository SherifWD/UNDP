<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $rolesConfig = (array) config('rbac.roles', []);

        $permissions = collect($rolesConfig)
            ->pluck('permissions')
            ->flatten()
            ->filter(fn ($permission) => is_string($permission) && $permission !== '')
            ->unique()
            ->values();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'api');
        }

        foreach ($rolesConfig as $roleName => $definition) {
            $role = Role::findOrCreate($roleName, 'api');
            $rolePermissions = collect($definition['permissions'] ?? [])
                ->filter(fn ($permission) => is_string($permission) && $permission !== '')
                ->values()
                ->all();

            $role->syncPermissions($rolePermissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
