<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\FormEntry;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Contact form submissions (SureForms entries).
 */
class MessageController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['status' => ['nullable', Rule::in(['all', 'unread', 'read', 'trash'])]]);
        $status = $request->query('status', 'all');

        $messages = FormEntry::query()
            ->when($status === 'all', fn ($q) => $q->notTrashed())
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->query('q'), fn ($q, $term) => $q->where('form_data', 'like', '%'.addcslashes($term, '%_').'%'))
            ->orderByDesc('created_at')
            ->orderByDesc('ID')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return MessageResource::collection($messages)->additional([
            'counts' => [
                'all' => FormEntry::notTrashed()->count(),
                'unread' => FormEntry::where('status', 'unread')->count(),
                'read' => FormEntry::where('status', 'read')->count(),
                'trash' => FormEntry::where('status', 'trash')->count(),
            ],
        ]);
    }

    public function show(int $id)
    {
        $entry = FormEntry::findOrFail($id);

        if ($entry->status === 'unread') {
            $entry->update(['status' => 'read']);
        }

        return new MessageResource($entry);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['unread', 'read', 'trash'])]]);

        $entry = FormEntry::findOrFail($id);
        $before = $entry->status;
        $entry->update($data);

        $from = $entry->field('name') ?: 'sin nombre';
        $verb = match (true) {
            $data['status'] === 'trash' => 'movió a la papelera',
            $before === 'trash' => 'restauró',
            $data['status'] === 'unread' => 'marcó como no leído',
            default => 'marcó como leído',
        };
        ActivityLogger::log('mensajes', $data['status'], "{$verb} el mensaje de «{$from}»", $entry, ['title' => $from]);

        return new MessageResource($entry);
    }

    /**
     * Delete permanently (only from the trash).
     */
    public function destroy(int $id)
    {
        $entry = FormEntry::findOrFail($id);
        abort_unless($entry->status === 'trash', 422, 'Primero mueve el mensaje a la papelera.');

        $from = $entry->field('name') ?: 'sin nombre';
        $entry->delete();
        ActivityLogger::log('mensajes', 'deleted', "eliminó definitivamente el mensaje de «{$from}»", null, ['title' => $from]);

        return response()->noContent();
    }
}
