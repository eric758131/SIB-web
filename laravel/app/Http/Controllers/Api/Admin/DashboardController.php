<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\MessageResource;
use App\Http\Resources\PostResource;
use App\Models\FormEntry;
use App\Models\Post;
use App\Models\TermTaxonomy;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $onlyOwn = ! $user->can('publicaciones.ver_todas');
        $posts = fn () => Post::news()->when($onlyOwn, fn ($q) => $q->where('post_author', $user->ID));

        return response()->json([
            'stats' => [
                'published' => $posts()->where('post_status', 'publish')->count(),
                'drafts' => $posts()->whereIn('post_status', ['draft', 'pending'])->count(),
                'scheduled' => $posts()->where('post_status', 'future')->count(),
                'categories' => TermTaxonomy::categories()->count(),
                'unread_messages' => $user->can('mensajes.gestionar') ? FormEntry::where('status', 'unread')->count() : null,
                'users' => $user->can('usuarios.gestionar') ? User::count() : null,
            ],
            'posts_per_month' => $this->postsPerMonth($onlyOwn ? $user->ID : null),
            'latest_posts' => PostResource::collection(
                $posts()->whereNotIn('post_status', ['trash', 'auto-draft'])
                    ->with(['thumbnail', 'author'])
                    ->orderByDesc('post_modified')
                    ->limit(5)
                    ->get()
            ),
            'latest_messages' => $user->can('mensajes.gestionar')
                ? MessageResource::collection(FormEntry::notTrashed()->orderByDesc('created_at')->limit(5)->get())
                : [],
            'activity' => $user->can('actividad.ver')
                ? ActivityResource::collection(Activity::with('causer')->latest('id')->limit(8)->get())
                : [],
        ]);
    }

    /**
     * Published posts per month over the last 12 months (oldest first).
     */
    private function postsPerMonth(?int $authorId): array
    {
        $start = now()->startOfMonth()->subMonths(11);

        $counts = Post::published()
            ->when($authorId, fn ($q) => $q->where('post_author', $authorId))
            ->where('post_date', '>=', $start)
            ->selectRaw("DATE_FORMAT(post_date, '%Y-%m') as ym, COUNT(*) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        return collect(range(0, 11))->map(function ($i) use ($start, $counts) {
            $month = $start->copy()->addMonths($i);

            return [
                'month' => $month->format('Y-m'),
                'label' => rtrim(ucfirst($month->locale('es')->translatedFormat('M')), '.'),
                'total' => (int) ($counts[$month->format('Y-m')] ?? 0),
            ];
        })->all();
    }
}
