<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\PanelToken;
use App\Support\WordPressHasher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Sign in with the WordPress username or email and password.
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('user_login', $data['login'])->orWhere('user_email', $data['login'])->first();

        if (! $user || ! $user->checkPassword($data['password'])) {
            throw ValidationException::withMessages(['login' => 'El usuario o la contraseña son incorrectos.']);
        }

        $user->adoptWordpressRole();

        if (! $user->canUsePanel()) {
            throw ValidationException::withMessages(['login' => 'Tu cuenta no tiene acceso al panel. Contacta a un administrador.']);
        }

        // Upgrade legacy hashes to the current WordPress format.
        if (WordPressHasher::needsRehash($user->user_pass)) {
            $user->setPassword($data['password']);
            $user->save();
        }

        $user->setMeta('sib_last_login', (string) now()->timestamp);
        ActivityLogger::log('auth', 'login', 'inició sesión en el panel', $user, ['title' => $user->display_name], $user);

        return response()->json([
            'token' => PanelToken::issue($user),
            'user' => (new UserResource($user))->withPermissions(),
        ]);
    }

    public function me(Request $request)
    {
        return (new UserResource($request->user()))->withPermissions();
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:250'],
            'email' => ['required', 'email', 'max:100', Rule::unique(User::class, 'user_email')->ignore($user->ID, 'ID')],
            'current_password' => ['required_with:password', 'nullable', 'string'],
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ]);

        if (! empty($data['password'])) {
            if (! $user->checkPassword($data['current_password'] ?? '')) {
                throw ValidationException::withMessages(['current_password' => 'La contraseña actual no es correcta.']);
            }
            $user->setPassword($data['password']);
        }

        $user->display_name = $data['name'];
        $user->user_email = $data['email'];
        $user->save();

        ActivityLogger::log('usuarios', 'updated', ! empty($data['password']) ? 'actualizó su perfil y contraseña' : 'actualizó su perfil', $user, ['title' => $user->display_name]);

        return (new UserResource($user))->withPermissions();
    }

    public function logout(Request $request)
    {
        PanelToken::revoke($request->bearerToken());

        return response()->noContent();
    }
}
