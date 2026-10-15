<?php

namespace App\Services;

use App\Models\Post;
use App\Models\TermTaxonomy;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes news items into {prefix}posts the same way WordPress does, so the
 * WordPress admin can keep reading them.
 */
class PostService
{
    /**
     * @param  array{title: string, slug?: ?string, content: string, excerpt?: ?string, status: string, published_at?: ?string, categories?: array<int>, thumbnail_id?: ?int}  $data
     */
    public function save(array $data, User $user, ?Post $post = null): Post
    {
        return DB::transaction(function () use ($data, $user, $post) {
            $isNew = ! $post;
            $post ??= new Post([
                'post_type' => 'post',
                'post_author' => $user->ID,
                'comment_status' => 'closed',
                'ping_status' => 'closed',
                'post_password' => '',
                'to_ping' => '',
                'pinged' => '',
                'post_content_filtered' => '',
                'post_parent' => 0,
                'menu_order' => 0,
                'post_mime_type' => '',
                'comment_count' => 0,
                'guid' => '',
            ]);

            $now = now();
            $date = ! empty($data['published_at'])
                ? Carbon::parse($data['published_at'])->setTimezone(config('app.timezone'))
                : ($isNew || $post->post_status === 'draft' ? $now : $post->post_date);

            // A published post dated in the future is "scheduled", like in WordPress.
            $status = $data['status'];
            if ($status === 'publish' && $date->isFuture()) {
                $status = 'future';
            }

            $oldCategoryIds = $isNew ? [] : $post->categories()->pluck('term_taxonomy.term_taxonomy_id')->all();

            $post->fill([
                'post_title' => $data['title'],
                'post_name' => Post::uniqueSlug(($data['slug'] ?? null) ?: $data['title'], $post->ID),
                'post_content' => $data['content'],
                'post_excerpt' => $data['excerpt'] ?? '',
                'post_status' => $status,
                'post_date' => $date,
                'post_date_gmt' => $status === 'draft' && empty($data['published_at'])
                    ? '0000-00-00 00:00:00'
                    : $date->copy()->utc()->format('Y-m-d H:i:s'),
                'post_modified' => $now,
                'post_modified_gmt' => $now->copy()->utc()->format('Y-m-d H:i:s'),
            ]);

            $post->save();

            if ($isNew) {
                $post->guid = config('wordpress.site_url').'/?p='.$post->ID;
                $post->save();
            }

            $categoryIds = $data['categories'] ?? [];
            if (! $categoryIds && ($default = TermTaxonomy::defaultCategoryId())) {
                $categoryIds = [$default];
            }
            $post->categories()->sync($categoryIds);

            $this->setThumbnail($post, $data['thumbnail_id'] ?? null);
            $post->setMeta('_edit_last', (string) $user->ID);

            TermTaxonomy::refreshCounts([...$oldCategoryIds, ...$categoryIds]);

            return $post;
        });
    }

    /**
     * Move to the trash the way wp_trash_post() does.
     */
    public function trash(Post $post): void
    {
        DB::transaction(function () use ($post) {
            $post->setMeta('_wp_trash_meta_status', $post->post_status);
            $post->setMeta('_wp_trash_meta_time', (string) now()->timestamp);

            $post->post_status = 'trash';
            $post->post_name = $post->post_name.'__trashed';
            $post->save();

            TermTaxonomy::refreshCounts($post->categories()->pluck('term_taxonomy.term_taxonomy_id')->all());
        });
    }

    public function restore(Post $post): void
    {
        DB::transaction(function () use ($post) {
            $previous = $post->getMeta('_wp_trash_meta_status') ?: 'draft';

            $post->post_status = $previous;
            $post->post_name = Post::uniqueSlug(preg_replace('/__trashed$/', '', $post->post_name), $post->ID);
            $post->save();

            $post->setMeta('_wp_trash_meta_status', null);
            $post->setMeta('_wp_trash_meta_time', null);

            TermTaxonomy::refreshCounts($post->categories()->pluck('term_taxonomy.term_taxonomy_id')->all());
        });
    }

    /**
     * Delete permanently, together with its meta, revisions and term links.
     */
    public function destroy(Post $post): void
    {
        DB::transaction(function () use ($post) {
            $categoryIds = $post->categories()->pluck('term_taxonomy.term_taxonomy_id')->all();

            $revisionIds = Post::where('post_parent', $post->ID)->where('post_type', 'revision')->pluck('ID');
            DB::table('postmeta')->whereIn('post_id', $revisionIds)->delete();
            Post::whereIn('ID', $revisionIds)->delete();

            $post->categories()->detach();
            $post->meta()->delete();
            $post->delete();

            TermTaxonomy::refreshCounts($categoryIds);
        });
    }

    private function setThumbnail(Post $post, ?int $attachmentId): void
    {
        $valid = $attachmentId && Post::where('ID', $attachmentId)->where('post_type', 'attachment')->exists();

        $post->setMeta('_thumbnail_id', $valid ? (string) $attachmentId : null);
    }
}
