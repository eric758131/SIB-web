<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserMeta;
use Illuminate\Support\Str;

/**
 * Admin panel bearer tokens. Each token is one wp_usermeta row
 * ("sib_panel_token" => "<sha256>|<expires timestamp>"), so no extra tables are needed.
 * The token handed to the client has the form "<user ID>|<random secret>".
 */
class PanelToken
{
    public const META_KEY = 'sib_panel_token';

    public static function issue(User $user): string
    {
        $secret = Str::random(64);
        $expires = now()->addDays(config('wordpress.token_ttl_days'))->timestamp;

        // Clean up this user's expired tokens while we are here.
        $user->meta()->where('meta_key', self::META_KEY)->get()
            ->filter(fn (UserMeta $m) => (int) Str::after($m->meta_value, '|') < now()->timestamp)
            ->each->delete();

        $user->meta()->create([
            'meta_key' => self::META_KEY,
            'meta_value' => hash('sha256', $secret).'|'.$expires,
        ]);

        return $user->ID.'|'.$secret;
    }

    public static function resolve(?string $token): ?User
    {
        $row = self::find($token);
        if (! $row) {
            return null;
        }

        if ((int) Str::after($row->meta_value, '|') < now()->timestamp) {
            $row->delete();

            return null;
        }

        return User::find($row->user_id);
    }

    public static function revoke(?string $token): void
    {
        self::find($token)?->delete();
    }

    public static function revokeAll(User $user): void
    {
        $user->meta()->where('meta_key', self::META_KEY)->delete();
    }

    private static function find(?string $token): ?UserMeta
    {
        if (! $token || ! str_contains($token, '|')) {
            return null;
        }

        [$userId, $secret] = explode('|', $token, 2);
        if (! ctype_digit($userId) || $secret === '') {
            return null;
        }

        return UserMeta::where('user_id', (int) $userId)
            ->where('meta_key', self::META_KEY)
            ->where('meta_value', 'like', hash('sha256', $secret).'|%')
            ->first();
    }
}
