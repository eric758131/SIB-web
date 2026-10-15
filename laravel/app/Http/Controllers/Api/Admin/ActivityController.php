<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity;

/**
 * History of everything done in the panel (spatie/laravel-activitylog).
 */
class ActivityController extends Controller
{
    public const LOGS = [
        'publicaciones' => 'Publicaciones',
        'categorias' => 'Categorías',
        'medios' => 'Medios',
        'mensajes' => 'Mensajes',
        'usuarios' => 'Usuarios',
        'roles' => 'Roles y permisos',
        'auth' => 'Inicios de sesión',
    ];

    public function index(Request $request)
    {
        $request->validate([
            'log' => ['nullable', Rule::in(array_keys(self::LOGS))],
            'user' => ['nullable', 'integer'],
        ]);

        $activities = Activity::with('causer')
            ->when($request->query('log'), fn ($q, $log) => $q->where('log_name', $log))
            ->when($request->query('user'), fn ($q, $id) => $q->where('causer_type', (new User)->getMorphClass())->where('causer_id', $id))
            ->when($request->query('q'), fn ($q, $term) => $q->where('description', 'like', "%{$term}%"))
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return ActivityResource::collection($activities)->additional([
            'logs' => collect(self::LOGS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'users' => User::whereIn('ID', Activity::where('causer_type', (new User)->getMorphClass())->distinct()->pluck('causer_id'))
                ->orderBy('display_name')
                ->get(['ID', 'display_name'])
                ->map(fn (User $u) => ['id' => $u->ID, 'name' => $u->display_name]),
        ]);
    }
}
