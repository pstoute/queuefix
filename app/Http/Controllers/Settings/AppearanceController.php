<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class AppearanceController extends Controller
{
    public function index(): Response
    {
        $settings = Setting::where('group', 'appearance')
            ->get()
            ->pluck('value', 'key');

        return Inertia::render('Settings/Appearance', [
            'settings' => $settings,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'accent_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'logo' => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('branding', 'public');
            Setting::updateOrCreate(
                ['key' => 'logo_path'],
                ['value' => $path, 'group' => 'appearance']
            );
        }

        if (isset($validated['accent_color'])) {
            Setting::updateOrCreate(
                ['key' => 'accent_color'],
                ['value' => $validated['accent_color'], 'group' => 'appearance']
            );
        }

        return back()->with('success', 'Appearance updated.');
    }
}
