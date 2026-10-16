<?php

namespace App\Http\Resources;

use App\Models\TermTaxonomy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Post */
class PostResource extends JsonResource
{
    private bool $withContent = false;

    /**
     * Include the full HTML content (detail and edit views).
     */
    public function full(): static
    {
        $this->withContent = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $categories = $this->relationLoaded('categories')
            ? $this->categories->map(fn (TermTaxonomy $tt) => [
                'id' => $tt->term_taxonomy_id,
                'name' => $tt->term?->name,
                'slug' => $tt->term?->slug,
            ])->values()
            : [];

        return [
            'id' => $this->ID,
            'title' => html_entity_decode($this->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'slug' => $this->post_name,
            'excerpt' => $this->excerptText(),
            'manual_excerpt' => $this->when($this->withContent, $this->post_excerpt),
            'content' => $this->when($this->withContent, fn () => $this->renderedContent()),
            'gallery' => $this->when($this->withContent, fn () => $this->galleryImages()),
            'links' => $this->when($this->withContent, fn () => $this->externalLinks()),
            'cover_image' => $this->relationLoaded('thumbnail') ? $this->coverUrl() : $this->firstContentImage(),
            'thumbnail_id' => $this->relationLoaded('thumbnail') ? $this->thumbnail?->ID : null,
            'status' => $this->post_status,
            'published_at' => $this->post_date?->toIso8601String(),
            'updated_at' => $this->post_modified?->toIso8601String(),
            'categories' => $categories,
            'author' => $this->whenLoaded('author', fn () => $this->author ? [
                'id' => $this->author->ID,
                'name' => $this->author->display_name,
            ] : null),
        ];
    }
}
