<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A SureForms submission ({prefix}srfm_entries). form_data is a JSON object whose
 * keys look like "srfm-email-<block id>-lbl-<base64 label>-<field slug>".
 */
class FormEntry extends Model
{
    protected $table = 'srfm_entries';

    protected $primaryKey = 'ID';

    protected $fillable = ['form_id', 'user_id', 'form_data', 'logs', 'notes', 'submission_info', 'status', 'type', 'extras', 'language'];

    protected function casts(): array
    {
        return [
            'form_data' => 'array',
            'submission_info' => 'array',
        ];
    }

    /**
     * Keys used by the contact form (id 1113) on the current site, so new entries
     * stay readable from the SureForms screens in WordPress.
     */
    public const CONTACT_KEYS = [
        'name' => 'srfm-input-0e439b4c-lbl-Tm9tYnJl-name',
        'email' => 'srfm-email-0ea6a2f8-lbl-Q29ycmVvIEVsZWN0csOzbmljbw-email',
        'phone' => 'srfm-input-65097f6b-lbl-TsO6bWVybyBkZSBUZWzDqWZvbm8-phone-number',
        'message' => 'srfm-textarea-9b74662a-lbl-RXNjcmliZSB1biBNZW5zYWpl-type-a-message',
    ];

    public function scopeNotTrashed(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'trash'));
    }

    /**
     * Read a field by its slug suffix ("name", "email", "phone-number", ...).
     */
    public function field(string $slug): ?string
    {
        foreach ((array) $this->form_data as $key => $value) {
            if (Str::endsWith($key, '-'.$slug)) {
                return is_array($value) ? implode(', ', $value) : (string) $value;
            }
        }

        return null;
    }

    /**
     * All fields as label => value, decoding the label embedded in each key.
     */
    public function fields(): array
    {
        $fields = [];
        foreach ((array) $this->form_data as $key => $value) {
            $label = preg_match('/-lbl-([A-Za-z0-9+\/]+)-/', $key, $m)
                ? base64_decode($m[1].str_repeat('=', (4 - strlen($m[1]) % 4) % 4))
                : $key;
            if (! $label || ! mb_check_encoding($label, 'UTF-8')) {
                $label = $key;
            }
            $fields[] = ['label' => $label, 'value' => is_array($value) ? implode(', ', $value) : (string) $value];
        }

        return $fields;
    }
}
