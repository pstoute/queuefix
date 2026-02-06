<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tags = Tag::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where('name', 'ilike', '%' . $request->search . '%');
            })
            ->orderBy('name')
            ->get();

        return response()->json($tags);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50|unique:tags,name',
            'color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        $tag = Tag::create($validated);

        return response()->json($tag, 201);
    }

    public function attachToTicket(Request $request, Ticket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'tag_ids' => 'required|array',
            'tag_ids.*' => 'exists:tags,id',
        ]);

        $ticket->tags()->syncWithoutDetaching($validated['tag_ids']);

        return back()->with('success', 'Tags updated.');
    }

    public function detachFromTicket(Ticket $ticket, Tag $tag): RedirectResponse
    {
        $ticket->tags()->detach($tag->id);

        return back()->with('success', 'Tag removed.');
    }
}
