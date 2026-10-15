<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Post;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\PanelPermissions;
use App\Support\PanelToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Manages WordPress users ({prefix}users + {prefix}usermeta) and their panel role (Spatie).
 */
class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::with(['meta', 'roles'])
            ->withCount(['posts' => fn ($q) => $q->news()->where('post_status', 'publish')])
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('user_login', 'like', "%{$term}%")
                ->orWhere('user_email', 'like', "%{$term}%")
                ->orWhere('display_name', 'like', "%{$term}%")))
            ->when($request->query('role'), fn ($q, $role) => $role === 'none' ? $q->doesntHave('roles') : $q->role($role))
            ->orderBy('display_name')
            ->get();

        return UserResource::collection($users)->additional([
            'roles' => $this->roleOptions(),
        ]);
    }

    public function show(int $id)
    {
        return new UserResource(User::with(['meta', 'roles'])->findOrFail($id));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9 _.\-@]+$/', Rule::unique(User::class, 'user_login')],
            'name' => ['required', 'string', 'max:250'],
            'email' => ['required', 'email', 'max:100', Rule::unique(User::class, 'user_email')],
            'role' => ['present', 'nullable', $this->roleRule()],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'username.regex' => 'El usuario solo puede tener letras, números, espacios y los caracteres _ . - @',
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = new User([
                'user_login' => $data['username'],
                'user_nicename' => Str::limit(Str::slug($data['username']), 50, ''),
                'user_email' => $data['email'],
                'user_url' => '',
                'user_registered' => now()->utc(),
                'user_activation_key' => '',
                'user_status' => 0,
                'display_name' => $data['name'],
            ]);
            $user->setPassword($data['password']);
            $user->save();

            // Default meta WordPress creates for every new user.
            foreach ([
                'nickname' => $data['username'],
                'first_name' => '',
                'last_name' => '',
                'description' => '',
                'rich_editing' => 'true',
                'syntax_highlighting' => 'true',
                'comment_shortcuts' => 'false',
                'admin_color' => 'fresh',
                'use_ssl' => '0',
                'show_admin_bar_front' => 'true',
                'locale' => '',
            ] as $key => $value) {
                $user->meta()->create(['meta_key' => $key, 'meta_value' => $value]);
            }
            $user->assignPanelRole($data['role'] ?: null);

            return $user;
        });

        ActivityLogger::log('usuarios', 'created', "creó el usuario «{$user->display_name}» (".PanelPermissions::label($data['role'] ?: null).')', $user, ['title' => $user->display_name, 'role' => $data['role'] ?: null]);

        return (new UserResource($user->load(['meta', 'roles'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $id)
    {
        $user = User::with('roles')->findOrFail($id);
        $isSelf = $user->ID === $request->user()->ID;
        $previousRole = $user->panelRole()?->name;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:250'],
            'email' => ['required', 'email', 'max:100', Rule::unique(User::class, 'user_email')->ignore($user->ID, 'ID')],
            'role' => ['present', 'nullable', $this->roleRule()],
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ]);

        $newRole = $data['role'] ?: null;
        if ($isSelf && $newRole !== $previousRole) {
            throw ValidationException::withMessages(['role' => 'No puedes cambiar tu propio rol.']);
        }

        DB::transaction(function () use ($user, $data, $newRole) {
            $user->display_name = $data['name'];
            $user->user_email = $data['email'];
            if (! empty($data['password'])) {
                $user->setPassword($data['password']);
            }
            $user->save();

            $user->assignPanelRole($newRole);
        });

        // Changing the password or removing access signs the user out everywhere.
        if (! $isSelf && (! empty($data['password']) || ! $user->canUsePanel())) {
            PanelToken::revokeAll($user);
        }

        $changes = [];
        if ($newRole !== $previousRole) {
            $changes[] = 'cambió su rol a '.PanelPermissions::label($newRole);
        }
        if (! empty($data['password'])) {
            $changes[] = 'cambió su contraseña';
        }
        $description = "editó el usuario «{$user->display_name}»".($changes ? ' ('.implode(', ', $changes).')' : '');
        ActivityLogger::log('usuarios', 'updated', $description, $user, ['title' => $user->display_name, 'from' => $previousRole, 'role' => $newRole]);

        return new UserResource($user->load(['meta', 'roles']));
    }

    /**
     * Delete a user and hand their posts over to the administrator doing it, as WordPress offers.
     */
    public function destroy(Request $request, int $id)
    {
        $user = User::findOrFail($id);
        $admin = $request->user();

        if ($user->ID === $admin->ID) {
            throw ValidationException::withMessages(['user' => 'No puedes eliminar tu propia cuenta.']);
        }

        $name = $user->display_name;
        DB::transaction(function () use ($user, $admin) {
            Post::where('post_author', $user->ID)->update(['post_author' => $admin->ID]);
            $user->syncRoles([]);
            $user->meta()->delete();
            $user->delete();
        });

        ActivityLogger::log('usuarios', 'deleted', "eliminó el usuario «{$name}»", null, ['title' => $name]);

        return response()->noContent();
    }

    private function roleRule()
    {
        return Rule::in(Role::where('guard_name', PanelPermissions::GUARD)->pluck('name')->all());
    }

    private function roleOptions(): array
    {
        return Role::where('guard_name', PanelPermissions::GUARD)
            ->orderBy('id')
            ->get()
            ->map(fn (Role $r) => ['value' => $r->name, 'label' => PanelPermissions::label($r->name)])
            ->push(['value' => '', 'label' => 'Sin acceso'])
            ->values()
            ->all();
    }
}
