<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\FormEntry */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ID,
            'form_id' => $this->form_id,
            'name' => $this->field('name'),
            'email' => $this->field('email'),
            'phone' => $this->field('phone-number'),
            'message' => $this->field('type-a-message'),
            'fields' => $this->fields(),
            'status' => $this->status ?: 'unread',
            'device' => $this->submission_info['device_name'] ?? null,
            'browser' => $this->submission_info['browser_name'] ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
