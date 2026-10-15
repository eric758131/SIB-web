<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Thin wrapper over spatie/laravel-activitylog so every panel action is logged the same way.
 */
class ActivityLogger
{
    /**
     * @param  string  $event  short machine name: created, updated, published, trashed, ...
     * @param  string  $description  sentence shown in the panel ("publicó la publicación")
     * @param  array<string, mixed>  $properties  extra data; "title" is shown as the subject name
     */
    public static function log(string $log, string $event, string $description, ?Model $subject = null, array $properties = [], ?Model $causer = null): void
    {
        $logger = activity($log)
            ->event($event)
            ->withProperties($properties);

        $causer ??= Auth::user();
        if ($causer) {
            $logger->causedBy($causer);
        }
        if ($subject) {
            $logger->performedOn($subject);
        }

        $logger->log($description);
    }
}
