<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Term;
use App\Models\TermTaxonomy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\ActivityLogger;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function index()
    {
        $default = TermTaxonomy::defaultCategoryId();

        return TermTaxonomy::categories()
            ->with('term')
            ->get()
            ->map(fn (TermTaxonomy $tt) => $this->present($tt, $default))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $tt = DB::transaction(function () use ($data) {
            $term = Term::create(['name' => $data['name'], 'slug' => $this->uniqueSlug(($data['slug'] ?? null) ?: $data['name']), 'term_group' => 0]);

            return TermTaxonomy::create([
                'term_id' => $term->term_id,
                'taxonomy' => 'category',
                'description' => $data['description'] ?? '',
                'parent' => 0,
                'count' => 0,
            ]);
        });

        ActivityLogger::log('categorias', 'created', "creó la categoría «{$data['name']}»", $tt, ['title' => $data['name']]);

        return response()->json($this->present($tt->load('term'), TermTaxonomy::defaultCategoryId()), 201);
    }

    public function update(Request $request, int $id)
    {
        $tt = TermTaxonomy::categories()->with('term')->findOrFail($id);
        $data = $this->validated($request);

        DB::transaction(function () use ($tt, $data) {
            $tt->term->update([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug(($data['slug'] ?? null) ?: $data['name'], $tt->term_id),
            ]);
            $tt->update(['description' => $data['description'] ?? '']);
        });

        ActivityLogger::log('categorias', 'updated', "editó la categoría «{$data['name']}»", $tt, ['title' => $data['name']]);

        return $this->present($tt->fresh('term'), TermTaxonomy::defaultCategoryId());
    }

    /**
     * Delete a category; its posts fall back to the default category, as in WordPress.
     */
    public function destroy(int $id)
    {
        $tt = TermTaxonomy::categories()->with('term')->findOrFail($id);
        $default = TermTaxonomy::defaultCategoryId();
        $name = $tt->term?->name;

        if ($tt->term_taxonomy_id === $default) {
            throw ValidationException::withMessages(['category' => 'No se puede eliminar la categoría por defecto.']);
        }

        DB::transaction(function () use ($tt, $default) {
            $postIds = DB::table('term_relationships')->where('term_taxonomy_id', $tt->term_taxonomy_id)->pluck('object_id');
            DB::table('term_relationships')->where('term_taxonomy_id', $tt->term_taxonomy_id)->delete();

            foreach ($postIds as $postId) {
                $hasOther = DB::table('term_relationships as tr')
                    ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'tr.term_taxonomy_id')
                    ->where('tr.object_id', $postId)
                    ->where('tt.taxonomy', 'category')
                    ->exists();

                if (! $hasOther && $default) {
                    DB::table('term_relationships')->insert(['object_id' => $postId, 'term_taxonomy_id' => $default, 'term_order' => 0]);
                }
            }

            DB::table('termmeta')->where('term_id', $tt->term_id)->delete();
            $termId = $tt->term_id;
            $tt->delete();
            if (! TermTaxonomy::where('term_id', $termId)->exists()) {
                Term::whereKey($termId)->delete();
            }

            TermTaxonomy::refreshCounts([$default]);
        });

        ActivityLogger::log('categorias', 'deleted', "eliminó la categoría «{$name}»", null, ['title' => $name]);

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function uniqueSlug(string $text, ?int $ignoreTermId = null): string
    {
        $base = Str::slug($text) ?: 'categoria';
        $slug = $base;
        $i = 2;

        while (Term::where('slug', $slug)->when($ignoreTermId, fn ($q) => $q->where('term_id', '!=', $ignoreTermId))->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    private function present(TermTaxonomy $tt, ?int $default): array
    {
        return [
            'id' => $tt->term_taxonomy_id,
            'name' => $tt->term->name,
            'slug' => $tt->term->slug,
            'description' => $tt->description,
            'count' => $tt->count,
            'is_default' => $tt->term_taxonomy_id === $default,
        ];
    }
}
