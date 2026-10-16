<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Uploads stored as WordPress attachments: the file goes to wp-content/uploads/YYYY/MM
 * and a post_type "attachment" row is created for it.
 */
class MediaController extends Controller
{
    public function index(Request $request)
    {
        $media = Post::where('post_type', 'attachment')
            ->when($request->query('type') === 'image', fn ($q) => $q->where('post_mime_type', 'like', 'image/%'))
            ->when($request->query('q'), fn ($q, $term) => $q->where('post_title', 'like', "%{$term}%"))
            ->orderByDesc('post_date')
            ->paginate(min((int) $request->query('per_page', 24), 100));

        return response()->json([
            'data' => $media->getCollection()->map(fn (Post $a) => $this->present($a)),
            'meta' => ['current_page' => $media->currentPage(), 'last_page' => $media->lastPage(), 'total' => $media->total()],
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx'],
        ]);

        $file = $request->file('file');
        $folder = now()->format('Y/m');
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'archivo';
        $extension = strtolower($file->getClientOriginalExtension());

        $filename = "{$name}.{$extension}";
        for ($i = 1; Storage::disk('wp_uploads')->exists("{$folder}/{$filename}"); $i++) {
            $filename = "{$name}-{$i}.{$extension}";
        }

        $path = $file->storeAs($folder, $filename, 'wp_uploads');
        $now = now();

        $attachment = Post::create([
            'post_author' => $request->user()->ID,
            'post_date' => $now,
            'post_date_gmt' => $now->copy()->utc()->format('Y-m-d H:i:s'),
            'post_modified' => $now,
            'post_modified_gmt' => $now->copy()->utc()->format('Y-m-d H:i:s'),
            'post_content' => '',
            'post_title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'post_excerpt' => '',
            'post_status' => 'inherit',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_password' => '',
            'post_name' => Post::uniqueSlug($name),
            'to_ping' => '',
            'pinged' => '',
            'post_content_filtered' => '',
            'post_parent' => 0,
            'guid' => Storage::disk('wp_uploads')->url($path),
            'menu_order' => 0,
            'post_type' => 'attachment',
            'post_mime_type' => $file->getMimeType(),
            'comment_count' => 0,
        ]);

        $attachment->setMeta('_wp_attached_file', $path);

        if (str_starts_with((string) $file->getMimeType(), 'image/') && ($size = @getimagesize(Storage::disk('wp_uploads')->path($path)))) {
            $attachment->setMeta('_wp_attachment_metadata', serialize([
                'width' => $size[0],
                'height' => $size[1],
                'file' => $path,
                'filesize' => $file->getSize(),
                'sizes' => [],
                'image_meta' => [],
            ]));
        }

        ActivityLogger::log('medios', 'created', "subió el archivo «{$attachment->post_title}»", $attachment, ['title' => $attachment->post_title, 'url' => $attachment->guid]);

        return response()->json($this->present($attachment), 201);
    }

    private function present(Post $attachment): array
    {
        return [
            'id' => $attachment->ID,
            'title' => $attachment->post_title,
            'url' => Post::publicUrl($attachment->guid),
            'mime_type' => $attachment->post_mime_type,
            'is_image' => str_starts_with($attachment->post_mime_type, 'image/'),
            'uploaded_at' => $attachment->post_date?->toIso8601String(),
        ];
    }
}
