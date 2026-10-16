<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Str;

/**
 * A row of {prefix}posts. News items are post_type "post"; images and PDFs are "attachment".
 *
 * @property int $ID
 * @property string $post_title
 * @property string $post_name
 * @property string $post_content
 * @property string $post_excerpt
 * @property string $post_status
 * @property string $post_type
 * @property string $guid
 */
class Post extends Model
{
    /** postmeta keys: JSON list of attachment IDs / JSON list of {title, url}. */
    public const GALLERY_META = '_sib_gallery';

    public const LINKS_META = '_sib_links';

    protected $table = 'posts';

    protected $primaryKey = 'ID';

    public $timestamps = false;

    protected $guarded = ['ID'];

    protected function casts(): array
    {
        return [
            'post_date' => 'datetime',
            'post_modified' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'post_author', 'ID');
    }

    public function meta(): HasMany
    {
        return $this->hasMany(PostMeta::class, 'post_id', 'ID');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(TermTaxonomy::class, 'term_relationships', 'object_id', 'term_taxonomy_id', 'ID', 'term_taxonomy_id')
            ->where('taxonomy', 'category')
            ->with('term');
    }

    /**
     * Featured image: postmeta "_thumbnail_id" points to an attachment post.
     */
    public function thumbnail(): HasOneThrough
    {
        return $this->hasOneThrough(Post::class, PostMeta::class, 'post_id', 'ID', 'ID', 'meta_value')
            ->where('postmeta.meta_key', '_thumbnail_id');
    }

    public function scopeNews(Builder $query): Builder
    {
        return $query->where('post_type', 'post');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->news()->where('post_status', 'publish');
    }

    public function getMeta(string $key): ?string
    {
        return $this->meta()->where('meta_key', $key)->value('meta_value');
    }

    public function setMeta(string $key, ?string $value): void
    {
        if ($value === null) {
            $this->meta()->where('meta_key', $key)->delete();

            return;
        }

        $this->meta()->updateOrCreate(['meta_key' => $key], ['meta_value' => $value]);
    }

    /**
     * Content ready for the browser: Gutenberg block comments removed.
     */
    public function renderedContent(): string
    {
        $html = preg_replace('/<!--\s*\/?wp:.*?-->/s', '', $this->post_content ?? '');

        // Old classic-editor posts have bare text separated by blank lines.
        if (! preg_match('/<(p|div|figure|h[1-6]|ul|ol|table|blockquote)[\s>]/i', $html)) {
            $paragraphs = preg_split('/\n\s*\n/', trim($html));
            $html = implode("\n", array_map(fn ($p) => '<p>'.nl2br(trim($p)).'</p>', array_filter($paragraphs)));
        }

        return trim(self::publicUrl($html));
    }

    /**
     * Uploads saved while APP_URL was http://localhost:8000 point to the developer's
     * machine; turn them into relative /wp-content/... paths so they load from any domain.
     */
    public static function publicUrl(?string $value): ?string
    {
        return $value === null ? null
            : preg_replace('#https?://(?:localhost|127\.0\.0\.1)(?::\d+)?(?=/wp-content/)#i', '', $value);
    }

    public function excerptText(int $length = 220): string
    {
        if (trim($this->post_excerpt ?? '') !== '') {
            return trim($this->post_excerpt);
        }

        $text = html_entity_decode(strip_tags($this->renderedContent()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $text)));

        // News items open with a byline and dateline; skip them in the summary.
        $text = preg_replace('/^Por:?\s+[^.]*?(?=La Paz,)/u', '', $text);

        return Str::limit(trim($text), $length);
    }

    /**
     * First image in the content, used when a post has no featured image.
     */
    public function firstContentImage(): ?string
    {
        return preg_match('/<img[^>]+src="([^"]+)"/i', $this->post_content ?? '', $m) ? self::publicUrl($m[1]) : null;
    }

    public function coverUrl(): ?string
    {
        return self::publicUrl($this->thumbnail?->guid ?: $this->firstContentImage());
    }

    /**
     * Extra images shown as a gallery under the content, in the chosen order.
     *
     * @return list<array{id: int, url: string, title: string}>
     */
    public function galleryImages(): array
    {
        $ids = json_decode($this->getMeta(self::GALLERY_META) ?? '[]', true) ?: [];
        if (! $ids) {
            return [];
        }

        $attachments = static::whereIn('ID', $ids)->where('post_type', 'attachment')->get()->keyBy('ID');

        return collect($ids)
            ->map(fn ($id) => $attachments->get($id))
            ->filter()
            ->map(fn (Post $a) => [
                'id' => $a->ID,
                'url' => self::publicUrl($a->guid),
                'title' => html_entity_decode($a->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ])
            ->values()
            ->all();
    }

    /**
     * Related links (agreements, documents, forms...) shown under the content.
     *
     * @return list<array{title: string, url: string}>
     */
    public function externalLinks(): array
    {
        $links = json_decode($this->getMeta(self::LINKS_META) ?? '[]', true);

        return is_array($links) ? array_values($links) : [];
    }

    /**
     * A post_name not used by any other post or page (both are served from /{slug}).
     */
    public static function uniqueSlug(string $text, ?int $ignoreId = null): string
    {
        $base = Str::limit(Str::slug($text), 190, '') ?: 'publicacion';
        $slug = $base;
        $i = 2;

        while (static::where('post_name', $slug)
            ->whereIn('post_type', ['post', 'page'])
            ->when($ignoreId, fn ($q) => $q->where('ID', '!=', $ignoreId))
            ->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
