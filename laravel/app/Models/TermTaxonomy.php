<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A term inside a taxonomy. Categories are the rows with taxonomy = "category".
 */
class TermTaxonomy extends Model
{
    protected $table = 'term_taxonomy';

    protected $primaryKey = 'term_taxonomy_id';

    public $timestamps = false;

    protected $fillable = ['term_id', 'taxonomy', 'description', 'parent', 'count'];

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'term_id', 'term_id');
    }

    public function scopeCategories(Builder $query): Builder
    {
        return $query->where('taxonomy', 'category');
    }

    /**
     * Recalculate the published-post count WordPress keeps on each term.
     */
    public static function refreshCounts(array $ids): void
    {
        foreach (array_unique(array_filter($ids)) as $id) {
            $count = DB::table('term_relationships as tr')
                ->join('posts as p', 'p.ID', '=', 'tr.object_id')
                ->where('tr.term_taxonomy_id', $id)
                ->where('p.post_type', 'post')
                ->where('p.post_status', 'publish')
                ->count();

            static::whereKey($id)->update(['count' => $count]);
        }
    }

    /**
     * term_taxonomy_id of the default category (option "default_category").
     */
    public static function defaultCategoryId(): ?int
    {
        $termId = (int) Option::get('default_category', 1);

        return static::categories()->where('term_id', $termId)->value('term_taxonomy_id');
    }
}
