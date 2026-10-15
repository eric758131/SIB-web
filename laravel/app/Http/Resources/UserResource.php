<?php

namespace App\Http\Resources;

use App\Support\PanelPermissions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /**
     * Include the effective permissions (only for the signed-in user).
     */
    private bool $withPermissions = false;

    public function withPermissions(): static
    {
        $this->withPermissions = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $role = $this->panelRole()?->name;
        $lastLogin = $this->getMeta('sib_last_login');

        return [
            'id' => $this->ID,
            'username' => $this->user_login,
            'name' => $this->display_name,
            'email' => $this->user_email,
            'role' => $role,
            'role_label' => PanelPermissions::label($role),
            'wordpress_role' => $this->wordpressRole(),
            'can_use_panel' => $this->canUsePanel(),
            'permissions' => $this->when($this->withPermissions, fn () => $this->getAllPermissions()->pluck('name')->values()),
            'posts_count' => $this->posts_count ?? null,
            // WordPress stores user_registered in UTC.
            'registered_at' => $this->getRawOriginal('user_registered')
                ? Carbon::parse($this->getRawOriginal('user_registered'), 'UTC')->setTimezone(config('app.timezone'))->toIso8601String()
                : null,
            'last_login_at' => $lastLogin ? Carbon::createFromTimestamp((int) $lastLogin, config('app.timezone'))->toIso8601String() : null,
        ];
    }
}
