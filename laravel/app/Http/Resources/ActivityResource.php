<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Spatie\Activitylog\Models\Activity */
class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $causer = $this->causer;

        return [
            'id' => $this->id,
            'log' => $this->log_name,
            'event' => $this->event,
            'description' => $this->description,
            'subject_id' => $this->subject_id,
            'subject_title' => $this->properties['title'] ?? null,
            'properties' => $this->properties,
            'causer' => $causer ? ['id' => $causer->ID, 'name' => $causer->display_name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
