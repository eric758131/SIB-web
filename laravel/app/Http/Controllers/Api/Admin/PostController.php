<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Services\PostService;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity;

class PostController extends Controller
{
    private const STATUSES = ['publish', 'draft', 'future', 'pending', 'private', 'trash'];

    public function __construct(private PostService $posts) {}

    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in([...self::STATUSES, 'all'])],
        ]);

        $status = $request->query('status', 'all');
        $onlyOwn = ! $request->user()->can('publicaciones.ver_todas');
        $userId = $request->user()->ID;

        $posts = Post::news()
            ->with(['categories', 'thumbnail', 'author'])
            ->when($status === 'all', fn ($q) => $q->whereNotIn('post_status', ['trash', 'auto-draft']))
            ->when($status !== 'all', fn ($q) => $q->where('post_status', $status))
            ->when($onlyOwn, fn ($q) => $q->where('post_author', $userId))
            ->when($request->query('q'), fn ($q, $term) => $q->where('post_title', 'like', "%{$term}%"))
            ->when($request->query('category'), fn ($q, $id) => $q->whereHas('categories', fn ($c) => $c->where('term_taxonomy.term_taxonomy_id', $id)))
            ->orderByDesc('post_date')
            ->paginate(min((int) $request->query('per_page', 15), 100));

        $counts = Post::news()
            ->when($onlyOwn, fn ($q) => $q->where('post_author', $userId))
            ->selectRaw('post_status, COUNT(*) as total')
            ->groupBy('post_status')
            ->pluck('total', 'post_status');

        return PostResource::collection($posts)->additional(['counts' => $counts]);
    }

    public function show(Request $request, int $id)
    {
        $post = $this->findEditable($request, $id);

        return (new PostResource($post->load(['categories', 'thumbnail', 'author'])))->full()
            ->additional(['history' => $this->history($post)]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $post = $this->posts->save($data, $request->user());

        ActivityLogger::log('publicaciones', 'created', $this->describe('creó', $post), $post, $this->props($post));

        return (new PostResource($post->load(['categories', 'thumbnail', 'author'])))->full()
            ->additional(['history' => $this->history($post)])
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, int $id)
    {
        $post = $this->findEditable($request, $id);
        $before = $post->post_status;

        $post = $this->posts->save($this->validated($request), $request->user(), $post);

        $verb = match (true) {
            $before !== 'publish' && $post->post_status === 'publish' => 'publicó',
            $before !== 'future' && $post->post_status === 'future' => 'programó',
            $before === 'publish' && $post->post_status === 'draft' => 'despublicó',
            $before !== 'pending' && $post->post_status === 'pending' => 'envió a revisión',
            default => 'editó',
        };
        ActivityLogger::log('publicaciones', $verb === 'editó' ? 'updated' : 'status', $this->describe($verb, $post), $post, $this->props($post, ['from' => $before]));

        return (new PostResource($post->load(['categories', 'thumbnail', 'author'])))->full()
            ->additional(['history' => $this->history($post)]);
    }

    /**
     * Move to the trash; with ?force=1 delete permanently.
     */
    public function destroy(Request $request, int $id)
    {
        $post = $this->findEditable($request, $id);

        if ($request->boolean('force')) {
            abort_unless($request->user()->can('publicaciones.eliminar_definitivo'), 403, 'No tienes permiso para eliminar definitivamente.');
            $props = $this->props($post);
            $this->posts->destroy($post);
            ActivityLogger::log('publicaciones', 'deleted', "eliminó definitivamente la publicación «{$props['title']}»", null, $props);
        } else {
            abort_unless($request->user()->can('publicaciones.eliminar'), 403, 'No tienes permiso para mover publicaciones a la papelera.');
            $this->posts->trash($post);
            ActivityLogger::log('publicaciones', 'trashed', $this->describe('movió a la papelera', $post), $post, $this->props($post));
        }

        return response()->noContent();
    }

    public function restore(Request $request, int $id)
    {
        $post = $this->findEditable($request, $id);
        abort_unless($request->user()->can('publicaciones.eliminar'), 403, 'No tienes permiso para restaurar publicaciones.');
        abort_unless($post->post_status === 'trash', 422, 'La publicación no está en la papelera.');

        $this->posts->restore($post);
        $post->refresh();
        ActivityLogger::log('publicaciones', 'restored', $this->describe('restauró', $post), $post, $this->props($post));

        return (new PostResource($post->load(['categories', 'thumbnail', 'author'])))->full();
    }

    private function findEditable(Request $request, int $id): Post
    {
        $post = Post::news()->findOrFail($id);
        $user = $request->user();

        abort_if(! $user->can('publicaciones.ver_todas') && $post->post_author != $user->ID, 403, 'Solo puedes editar tus propias publicaciones.');

        return $post;
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'slug' => ['nullable', 'string', 'max:190'],
            'content' => ['required', 'string'],
            'excerpt' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['publish', 'draft', 'pending'])],
            'published_at' => ['nullable', 'date'],
            'categories' => ['array'],
            'categories.*' => ['integer', Rule::exists('term_taxonomy', 'term_taxonomy_id')->where('taxonomy', 'category')],
            'thumbnail_id' => ['nullable', 'integer'],
        ]);

        // Without the "publish" permission posts wait for review, like WordPress authors/contributors.
        if (! $request->user()->can('publicaciones.publicar') && $data['status'] === 'publish') {
            $data['status'] = 'pending';
        }

        return $data;
    }

    private function title(Post $post): string
    {
        return html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function describe(string $verb, Post $post): string
    {
        return "{$verb} la publicación «{$this->title($post)}»";
    }

    private function props(Post $post, array $extra = []): array
    {
        return ['title' => $this->title($post), 'status' => $post->post_status, ...$extra];
    }

    private function history(Post $post)
    {
        return ActivityResource::collection(
            Activity::with('causer')
                ->where('subject_type', $post->getMorphClass())
                ->where('subject_id', $post->ID)
                ->latest('id')
                ->limit(15)
                ->get()
        );
    }
}
