<?php

namespace App\Http\Controllers\Agent;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\CannedResponseRequest;
use App\Models\CannedResponse;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CannedResponseRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CannedResponseController extends Controller
{
    public function __construct(private CannedResponseRenderer $renderer) {}

    public function index(Request $request): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $responses = CannedResponse::with('creator')
            ->when(
                $user->role !== UserRole::Admin,
                fn ($query) => $query->where('created_by', $user->id),
            )
            ->orderBy('title')
            ->get();

        if ($request->wantsJson()) {
            return response()->json($responses);
        }

        return Inertia::render('Settings/CannedResponses/Index', [
            'cannedResponses' => $responses,
        ]);
    }

    public function store(CannedResponseRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        CannedResponse::create([
            ...$validated,
            'is_active' => $validated['is_active'] ?? true,
            'visibility' => $validated['visibility'] ?? CannedResponse::VISIBILITY_ALL_AGENTS,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Canned response created.');
    }

    public function update(CannedResponseRequest $request, CannedResponse $cannedResponse): RedirectResponse
    {
        $this->authorizeManagement($request, $cannedResponse);

        $cannedResponse->update($request->validated());

        return back()->with('success', 'Canned response updated.');
    }

    public function destroy(Request $request, CannedResponse $cannedResponse): RedirectResponse
    {
        $this->authorizeManagement($request, $cannedResponse);
        $cannedResponse->delete();

        return back()->with('success', 'Canned response deleted.');
    }

    public function search(Request $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('update', $ticket);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $search = strtolower(trim($validated['search'] ?? ''));
        $responses = CannedResponse::query()
            ->availableTo($user)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($matching) use ($search): void {
                    $matching->whereRaw('LOWER(title) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(body) LIKE ?', ["%{$search}%"]);
                });
            })
            ->orderBy('title')
            ->limit(25)
            ->get(['id', 'title', 'body']);

        return response()->json(['canned_responses' => $responses]);
    }

    public function render(Request $request, Ticket $ticket, CannedResponse $cannedResponse): JsonResponse
    {
        Gate::authorize('update', $ticket);
        /** @var User $user */
        $user = $request->user();
        abort_unless($cannedResponse->isAvailableTo($user), 404);

        return response()->json([
            'id' => $cannedResponse->id,
            'title' => $cannedResponse->title,
            'body' => $this->renderer->render($cannedResponse->body, $ticket),
        ]);
    }

    private function authorizeManagement(Request $request, CannedResponse $cannedResponse): void
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->role !== UserRole::Admin && $cannedResponse->created_by !== $user->id) {
            abort(403);
        }
    }
}
