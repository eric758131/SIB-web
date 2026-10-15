<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\FormEntry;
use App\Models\Post;
use App\Models\TermTaxonomy;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    public function posts(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 9), 1), 50);

        $posts = Post::published()
            ->with(['categories', 'thumbnail'])
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('post_title', 'like', "%{$term}%")
                ->orWhere('post_content', 'like', "%{$term}%")))
            ->when($request->query('category'), fn ($q, $slug) => $q->whereHas('categories.term', fn ($t) => $t->where('slug', $slug)))
            ->orderByDesc('post_date')
            ->paginate($perPage);

        return PostResource::collection($posts);
    }

    public function post(string $slug)
    {
        $post = Post::published()->with(['categories', 'thumbnail', 'author'])->where('post_name', $slug)->firstOrFail();

        $related = Post::published()
            ->with('thumbnail')
            ->where('ID', '!=', $post->ID)
            ->orderByDesc('post_date')
            ->limit(3)
            ->get();

        return response()->json([
            'data' => (new PostResource($post))->full(),
            'related' => PostResource::collection($related),
            'previous' => $this->neighbour($post, '<'),
            'next' => $this->neighbour($post, '>'),
        ]);
    }

    public function categories()
    {
        return TermTaxonomy::categories()
            ->with('term')
            ->where('count', '>', 0)
            ->get()
            ->map(fn (TermTaxonomy $tt) => [
                'id' => $tt->term_taxonomy_id,
                'name' => $tt->term->name,
                'slug' => $tt->term->slug,
                'count' => $tt->count,
            ])
            ->sortBy('name')
            ->values();
    }

    /**
     * Store a contact form submission as a SureForms entry, like the current site does.
     */
    public function contact(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'message' => ['required', 'string', 'max:5000'],
            // Honeypot: real visitors never fill this hidden field.
            'website' => ['prohibited'],
        ]);

        $agent = (string) $request->userAgent();

        FormEntry::create([
            'form_id' => config('wordpress.contact_form_id'),
            'user_id' => 0,
            'form_data' => [
                FormEntry::CONTACT_KEYS['name'] => $data['name'],
                FormEntry::CONTACT_KEYS['email'] => $data['email'],
                FormEntry::CONTACT_KEYS['phone'] => $data['phone'] ?? '',
                FormEntry::CONTACT_KEYS['message'] => $data['message'],
            ],
            'submission_info' => [
                'user_ip' => '',
                'browser_name' => $this->browserName($agent),
                'device_name' => $this->deviceName($agent),
                'submission_url' => config('wordpress.site_url').'/contact/',
            ],
            'status' => 'unread',
            'extras' => '[]',
        ]);

        return response()->json(['message' => 'Gracias por escribirnos. Te responderemos pronto.'], 201);
    }

    private function neighbour(Post $post, string $operator): ?array
    {
        $neighbour = Post::published()
            ->where('post_date', $operator, $post->post_date)
            ->orderBy('post_date', $operator === '<' ? 'desc' : 'asc')
            ->first(['ID', 'post_title', 'post_name']);

        return $neighbour ? [
            'title' => html_entity_decode($neighbour->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'slug' => $neighbour->post_name,
        ] : null;
    }

    private function browserName(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Desconocido',
        };
    }

    private function deviceName(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Desconocido',
        };
    }
}
