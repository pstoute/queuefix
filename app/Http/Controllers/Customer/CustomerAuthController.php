<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Mail\CustomerMagicLinkMail;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class CustomerAuthController extends Controller
{
    public function showLogin(): Response
    {
        return Inertia::render('Customer/Auth/Login');
    }

    public function sendMagicLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => 'required|email']);

        $customer = Customer::firstOrCreate(
            ['email' => strtolower($request->email)],
            ['name' => explode('@', $request->email)[0]]
        );

        $url = URL::temporarySignedRoute(
            'customer.auth.verify',
            now()->addMinutes(15),
            ['customer' => $customer->id]
        );

        Mail::to($customer->email)->send(new CustomerMagicLinkMail($url, $customer));

        return back()->with('status', 'A sign-in link has been sent to your email.');
    }

    public function verify(Request $request, Customer $customer): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return redirect()->route('customer.login')
                ->with('error', 'This link has expired or is invalid.');
        }

        if (! $customer->email_verified_at) {
            $customer->update(['email_verified_at' => now()]);
        }

        Auth::guard('customer')->login($customer, true);

        return redirect()->route('customer.tickets.index');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('customer.login');
    }
}
