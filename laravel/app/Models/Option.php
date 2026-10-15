<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Option extends Model
{
    protected $table = 'options';

    protected $primaryKey = 'option_id';

    public $timestamps = false;

    protected $fillable = ['option_name', 'option_value', 'autoload'];

    public static function get(string $name, mixed $default = null): mixed
    {
        return static::where('option_name', $name)->value('option_value') ?? $default;
    }
}
