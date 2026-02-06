<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        $tags = Tag::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($request->search) . '%']);
            })
            ->orderBy('name')
            ->get();

        if ($request->wantsJson()) {
            return response()->json($tags);
        }

        return Inertia::render('Agent/Tags/Index', [
            'tags' => $tags,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50|unique:tags,name',
            'color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        Tag::create($validated);

        return back()->with('success', 'Tag created.');
    }

    public function attachToTicket(Request $request, Ticket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'tag_id' => 'required|exists:tags,id',
        ]);

        $ticket->tags()->syncWithoutDetaching([$validated['tag_id']]);

        return back()->with('success', 'Tag added.');
    }

    public function detachFromTicket(Ticket $ticket, Tag $tag): RedirectResponse
    {
        $ticket->tags()->detach($tag->id);

        return back()->with('success', 'Tag removed.');
    }
}
