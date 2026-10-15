<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\PanelPermissions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Roles and permissions of the admin panel (spatie/laravel-permission).
 */
class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::where('guard_name', PanelPermissions::GUARD)
            ->with('permissions')
            ->withCount('users')
            ->orderBy('id')
            ->get()
            ->map(fn (Role $role) => $this->present($role));

        $permissions = collect(PanelPermissions::PERMISSIONS)
            ->map(fn ($info, $name) => ['name' => $name, 'group' => $info[0], 'label' => $info[1]])
            ->values();

        return response()->json(['roles' => $roles, 'permissions' => $permissions]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $role = Role::create(['name' => $data['name'], 'guard_name' => PanelPermissions::GUARD]);
        $role->syncPermissions($data['permissions']);

        ActivityLogger::log('roles', 'created', "creó el rol «{$role->name}»", $role, ['title' => $role->name, 'permissions' => $data['permissions']]);

        return response()->json($this->present($role->load('permissions')->loadCount('users')), 201);
    }

    public function update(Request $request, int $id)
    {
        $role = Role::where('guard_name', PanelPermissions::GUARD)->findOrFail($id);
        $data = $this->validated($request, $role);

        if ($role->name === 'administrator') {
            throw ValidationException::withMessages(['permissions' => 'El rol Administrador siempre tiene todos los permisos.']);
        }

        if (! PanelPermissions::isSystemRole($role->name)) {
            $role->update(['name' => $data['name']]);
        }
        $role->syncPermissions($data['permissions']);

        // Keep the mirrored WordPress role of its users consistent with the new permissions.
        $wpRole = PanelPermissions::wordpressRoleFor($role);
        User::role($role->name)->get()->each(fn (User $u) => $u->setWordpressRole($wpRole));

        ActivityLogger::log('roles', 'updated', 'editó los permisos del rol «'.PanelPermissions::label($role->name).'»', $role, ['title' => PanelPermissions::label($role->name), 'permissions' => $data['permissions']]);

        return $this->present($role->load('permissions')->loadCount('users'));
    }

    public function destroy(int $id)
    {
        $role = Role::where('guard_name', PanelPermissions::GUARD)->withCount('users')->findOrFail($id);

        if (PanelPermissions::isSystemRole($role->name)) {
            throw ValidationException::withMessages(['role' => 'Los roles predeterminados no se pueden eliminar.']);
        }
        if ($role->users_count > 0) {
            throw ValidationException::withMessages(['role' => "Hay {$role->users_count} usuario(s) con este rol. Asígnales otro rol antes de eliminarlo."]);
        }

        $name = $role->name;
        $role->delete();
        ActivityLogger::log('roles', 'deleted', "eliminó el rol «{$name}»", null, ['title' => $name]);

        return response()->noContent();
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique(config('permission.table_names.roles'), 'name')->where('guard_name', PanelPermissions::GUARD)->ignore($role?->id),
            ],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(array_keys(PanelPermissions::PERMISSIONS))],
        ], [
            'name.unique' => 'Ya existe un rol con ese nombre.',
        ]) + ['permissions' => []];
    }

    private function present(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'label' => PanelPermissions::label($role->name),
            'is_system' => PanelPermissions::isSystemRole($role->name),
            'wordpress_role' => PanelPermissions::wordpressRoleFor($role),
            'users_count' => $role->users_count ?? 0,
            'permissions' => $role->permissions->pluck('name')->values(),
        ];
    }
}
