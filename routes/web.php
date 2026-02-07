<?php

use App\Http\Controllers\Agent\CannedResponseController;
use App\Http\Controllers\Agent\DashboardController;
use App\Http\Controllers\Agent\TagController;
use App\Http\Controllers\Agent\TicketController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\Customer\CustomerAuthController;
use App\Http\Controllers\Customer\CustomerTicketController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\GeneralSettingsController;
use App\Http\Controllers\Settings\MailboxController;
use App\Http\Controllers\Settings\SlaController;
use App\Http\Controllers\Settings\UserManagementController;
use Illuminate\Support\Facades\Route;

// Redirect root to login or dashboard
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('agent.dashboard')
        : redirect()->route('login');
});

// OAuth routes
Route::get('auth/{provider}/redirect', [SocialiteController::class, 'redirect'])
    ->name('auth.social.redirect');
Route::get('auth/{provider}/callback', [SocialiteController::class, 'callback'])
    ->name('auth.social.callback');

// Magic link routes
Route::middleware('guest')->group(function () {
    Route::get('auth/magic-link', [MagicLinkController::class, 'showForm'])
        ->name('auth.magic-link');
    Route::post('auth/magic-link', [MagicLinkController::class, 'send'])
        ->name('auth.magic-link.send');
});
Route::get('auth/magic-link/verify/{user}', [MagicLinkController::class, 'verify'])
    ->name('auth.magic-link.verify')
    ->middleware('signed');

// Agent dashboard routes
Route::middleware(['auth', 'verified'])->prefix('agent')->name('agent.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Tickets
    Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('tickets/create', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('tickets', [TicketController::class, 'store'])->name('tickets.store');
    Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('tickets/{ticket}/reply', [TicketController::class, 'reply'])->name('tickets.reply');
    Route::patch('tickets/{ticket}/status', [TicketController::class, 'updateStatus'])->name('tickets.status');
    Route::patch('tickets/{ticket}/priority', [TicketController::class, 'updatePriority'])->name('tickets.priority');
    Route::patch('tickets/{ticket}/assign', [TicketController::class, 'assign'])->name('tickets.assign');
    Route::post('tickets/{ticket}/merge', [TicketController::class, 'merge'])->name('tickets.merge');

    // Tags
    Route::get('tags', [TagController::class, 'index'])->name('tags.index');
    Route::post('tags', [TagController::class, 'store'])->name('tags.store');
    Route::post('tickets/{ticket}/tags', [TagController::class, 'attachToTicket'])->name('tickets.tags.attach');
    Route::delete('tickets/{ticket}/tags/{tag}', [TagController::class, 'detachFromTicket'])->name('tickets.tags.detach');

    // Canned responses
    Route::get('canned-responses', [CannedResponseController::class, 'index'])->name('canned-responses.index');
    Route::post('canned-responses', [CannedResponseController::class, 'store'])->name('canned-responses.store');
    Route::put('canned-responses/{cannedResponse}', [CannedResponseController::class, 'update'])->name('canned-responses.update');
    Route::delete('canned-responses/{cannedResponse}', [CannedResponseController::class, 'destroy'])->name('canned-responses.destroy');
    Route::get('canned-responses/{cannedResponse}/render', [CannedResponseController::class, 'render'])->name('canned-responses.render');
});

// Settings (admin only)
Route::middleware(['auth', 'verified'])->prefix('settings')->name('settings.')->group(function () {
    Route::get('general', [GeneralSettingsController::class, 'index'])->name('general.index');
    Route::put('general', [GeneralSettingsController::class, 'update'])->name('general.update');

    Route::get('appearance', [AppearanceController::class, 'index'])->name('appearance.index');
    Route::put('appearance', [AppearanceController::class, 'update'])->name('appearance.update');

    Route::resource('mailboxes', MailboxController::class)->except(['show']);
    Route::post('mailboxes/{mailbox}/test', [MailboxController::class, 'test'])->name('mailboxes.test');

    Route::get('sla', [SlaController::class, 'index'])->name('sla.index');
    Route::post('sla', [SlaController::class, 'store'])->name('sla.store');
    Route::put('sla/{slaPolicy}', [SlaController::class, 'update'])->name('sla.update');
    Route::delete('sla/{slaPolicy}', [SlaController::class, 'destroy'])->name('sla.destroy');

    Route::get('users', [UserManagementController::class, 'index'])->name('users.index');
    Route::post('users', [UserManagementController::class, 'store'])->name('users.store');
    Route::put('users/{user}', [UserManagementController::class, 'update'])->name('users.update');

    Route::get('canned-responses', [CannedResponseController::class, 'index'])->name('canned-responses.index');
    Route::post('canned-responses', [CannedResponseController::class, 'store'])->name('settings.canned-responses.store');
    Route::put('canned-responses/{cannedResponse}', [CannedResponseController::class, 'update'])->name('settings.canned-responses.update');
    Route::delete('canned-responses/{cannedResponse}', [CannedResponseController::class, 'destroy'])->name('settings.canned-responses.destroy');
});

// Profile
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Customer portal routes
Route::prefix('portal')->name('customer.')->group(function () {
    Route::middleware('guest:customer')->group(function () {
        Route::get('login', [CustomerAuthController::class, 'showLogin'])->name('login');
        Route::post('login', [CustomerAuthController::class, 'sendMagicLink'])->name('login.send');
    });

    Route::get('auth/verify/{customer}', [CustomerAuthController::class, 'verify'])
        ->name('auth.verify')
        ->middleware('signed');

    Route::middleware('auth:customer')->group(function () {
        Route::get('tickets', [CustomerTicketController::class, 'index'])->name('tickets.index');
        Route::get('tickets/{ticket}', [CustomerTicketController::class, 'show'])->name('tickets.show');
        Route::post('tickets/{ticket}/reply', [CustomerTicketController::class, 'reply'])->name('tickets.reply');
        Route::post('logout', [CustomerAuthController::class, 'logout'])->name('logout');
    });
});

require __DIR__ . '/auth.php';
