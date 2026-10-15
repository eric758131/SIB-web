<?php

namespace App\Models;

use App\Support\PanelPermissions;
use App\Support\WordPressHasher;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

/**
 * A WordPress user ({prefix}users).
 *
 * Panel access is managed with spatie/laravel-permission roles; the WordPress role in usermeta
 * "{prefix}capabilities" is kept in sync so the same account keeps working in WordPress.
 *
 * @property int $ID
 * @property string $user_login
 * @property string $user_pass
 * @property string $user_email
 * @property string $display_name
 */
class User extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    protected $primaryKey = 'ID';

    public $timestamps = false;

    /** Spatie guard for roles and permissions (the API authenticates through the "api" guard). */
    protected string $guard_name = PanelPermissions::GUARD;

    /** WordPress user_level for each role, kept in sync like WordPress does. */
    private const USER_LEVELS = ['administrator' => 10, 'editor' => 7, 'author' => 2, 'contributor' => 1, 'subscriber' => 0];

    protected $fillable = [
        'user_login',
        'user_pass',
        'user_nicename',
        'user_email',
        'user_url',
        'user_registered',
        'user_activation_key',
        'user_status',
        'display_name',
    ];

    protected $hidden = [
        'user_pass',
        'user_activation_key',
    ];

    protected function casts(): array
    {
        return [
            'user_registered' => 'datetime',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'user_pass';
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    public function meta(): HasMany
    {
        return $this->hasMany(UserMeta::class, 'user_id', 'ID');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'post_author', 'ID');
    }

    public function getMeta(string $key): ?string
    {
        if ($this->relationLoaded('meta')) {
            return $this->meta->firstWhere('meta_key', $key)?->meta_value;
        }

        return $this->meta()->where('meta_key', $key)->value('meta_value');
    }

    public function setMeta(string $key, ?string $value): void
    {
        $this->meta()->updateOrCreate(['meta_key' => $key], ['meta_value' => $value ?? '']);
        $this->unsetRelation('meta');
    }

    /**
     * The user's WordPress role ("administrator", "editor", ...) or null when it has none.
     */
    public function wordpressRole(): ?string
    {
        $caps = $this->getMeta(self::capabilitiesKey());
        $caps = $caps ? @unserialize($caps, ['allowed_classes' => false]) : [];

        if (! is_array($caps)) {
            return null;
        }

        foreach ($caps as $role => $granted) {
            if ($granted) {
                return $role;
            }
        }

        return null;
    }

    public function setWordpressRole(?string $role): void
    {
        $this->setMeta(self::capabilitiesKey(), serialize($role ? [$role => true] : []));
        $this->setMeta(config('wordpress.prefix').'user_level', (string) (self::USER_LEVELS[$role] ?? 0));
    }

    /**
     * The user's panel role (Spatie). Users have at most one.
     */
    public function panelRole(): ?Role
    {
        return $this->roles->first();
    }

    /**
     * Give the user a panel role (or none) and mirror it as a WordPress role.
     */
    public function assignPanelRole(?string $roleName): void
    {
        $this->syncRoles($roleName ? [$roleName] : []);
        $this->unsetRelation('roles');

        $this->setWordpressRole(PanelPermissions::wordpressRoleFor($this->panelRole()));
    }

    /**
     * Users created in WordPress after the panel was set up get their panel role on first sign-in.
     */
    public function adoptWordpressRole(): void
    {
        $wpRole = $this->wordpressRole();
        if (! $this->roles()->exists() && $wpRole && PanelPermissions::isSystemRole($wpRole)) {
            $this->assignRole($wpRole);
            $this->unsetRelation('roles');
        }
    }

    public function canUsePanel(): bool
    {
        return $this->roles->isNotEmpty();
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('administrator');
    }

    public function checkPassword(string $password): bool
    {
        return WordPressHasher::check($password, $this->user_pass);
    }

    public function setPassword(string $password): void
    {
        $this->user_pass = WordPressHasher::make($password);
    }

    public static function capabilitiesKey(): string
    {
        return config('wordpress.prefix').'capabilities';
    }
}
