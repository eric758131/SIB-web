<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PanelPermissions;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SyncPanelRoles extends Command
{
    protected $signature = 'sib:sync-roles {--reset : Restore the default permissions of the built-in roles}';

    protected $description = 'Create the panel permissions and roles (Spatie) and assign roles to WordPress users from their WordPress role';

    public function handle(): int
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(PanelPermissions::PERMISSIONS) as $name) {
            Permission::findOrCreate($name, PanelPermissions::GUARD);
        }

        foreach (PanelPermissions::DEFAULT_ROLES as $name => [$label, $permissions]) {
            $exists = Role::where('name', $name)->where('guard_name', PanelPermissions::GUARD)->exists();
            $role = Role::findOrCreate($name, PanelPermissions::GUARD);

            // Administrators always keep every permission; other roles only get defaults when new or reset.
            if ($name === 'administrator') {
                $role->syncPermissions(Permission::where('guard_name', PanelPermissions::GUARD)->get());
            } elseif (! $exists || $this->option('reset')) {
                $role->syncPermissions($permissions);
            }
            $this->line("Rol <info>{$label}</info> ({$name}) listo.");
        }

        $assigned = 0;
        User::with('meta')->get()->each(function (User $user) use (&$assigned) {
            $wpRole = $user->wordpressRole();
            if (! $user->roles()->exists() && $wpRole && PanelPermissions::isSystemRole($wpRole)) {
                $user->assignRole($wpRole);
                $assigned++;
                $this->line("  {$user->user_login} → {$wpRole}");
            }
        });

        $this->info("Listo. Roles asignados a {$assigned} usuario(s).");

        return self::SUCCESS;
    }
}
