<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserMeta extends Model
{
    protected $table = 'usermeta';

    protected $primaryKey = 'umeta_id';

    public $timestamps = false;

    protected $fillable = ['user_id', 'meta_key', 'meta_value'];
}
