<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Term extends Model
{
    protected $table = 'terms';

    protected $primaryKey = 'term_id';

    public $timestamps = false;

    protected $fillable = ['name', 'slug', 'term_group'];

    public function taxonomy(): HasOne
    {
        return $this->hasOne(TermTaxonomy::class, 'term_id', 'term_id');
    }
}
