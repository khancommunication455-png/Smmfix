<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\FundsController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;

// ────────────────────────────────────────────────────────────────────────────
// PUBLIC ROUTES
// ────────────────────────────────────────────────────────────────────────────

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : view('landing');
})->name('home');

// ────────────────────────────────────────────────────────────────────────────
// AUTH ROUTES
// ────────────────────────────────────────────────────────────────────────────

Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);

Route::get('/register', [RegisterController::class, 'showRegistrationForm'])->name('register');
Route::post('/register', [RegisterController::class, 'register']);

Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// ────────────────────────────────────────────────────────────────────────────
// AUTHENTICATED USER ROUTES
// ────────────────────────────────────────────────────────────────────────────

Route::middleware(['auth'])->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Orders
    Route::prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::get('new', [OrderController::class, 'create'])->name('create');
        Route::post('/', [OrderController::class, 'store'])->name('store');
        Route::get('{order}', [OrderController::class, 'show'])->name('show');
    });

    // Services
    Route::get('/services', [ServiceController::class, 'index'])->name('services.index');

    // Funds / Payments
    Route::prefix('funds')->name('funds.')->group(function () {
        Route::get('/', [FundsController::class, 'index'])->name('index');
        Route::post('stripe', [FundsController::class, 'stripe'])->name('stripe');
        Route::post('paypal', [FundsController::class, 'paypal'])->name('paypal');
        Route::post('manual', [FundsController::class, 'manual'])->name('manual');
    });

    // Transactions
    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');

    // Analytics
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');

    // Referrals
    Route::get('/referral', [ReferralController::class, 'index'])->name('referral.index');

    // Support Tickets
    Route::prefix('support')->name('tickets.')->group(function () {
        Route::get('/', [TicketController::class, 'index'])->name('index');
        Route::get('new', [TicketController::class, 'create'])->name('create');
        Route::post('/', [TicketController::class, 'store'])->name('store');
        Route::get('{ticket}', [TicketController::class, 'show'])->name('show');
        Route::post('{ticket}/reply', [TicketController::class, 'reply'])->name('reply');
    });
});

// ────────────────────────────────────────────────────────────────────────────
// ADMIN ROUTES
// ────────────────────────────────────────────────────────────────────────────

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    // Dashboard
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');

    // API Providers Management
    Route::prefix('providers')->name('providers.')->group(function () {
        Route::get('/', [AdminController::class, 'providersIndex'])->name('index');
        Route::get('create', [AdminController::class, 'providersCreate'])->name('create');
        Route::post('/', [AdminController::class, 'providersStore'])->name('store');
        Route::get('{provider}/edit', [AdminController::class, 'providersEdit'])->name('edit');
        Route::put('{provider}', [AdminController::class, 'providersUpdate'])->name('update');
        Route::post('{provider}/sync', [AdminController::class, 'syncProvider'])->name('sync');
    });

    // Synchronization
    Route::prefix('sync')->name('sync.')->group(function () {
        Route::post('all', [AdminController::class, 'syncAll'])->name('all');
        Route::post('services', [AdminController::class, 'syncServices'])->name('services');
        Route::post('orders', [AdminController::class, 'syncOrders'])->name('orders');
    });

    // Orders Management
    Route::prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [AdminController::class, 'ordersIndex'])->name('index');
        Route::patch('{order}/status', [AdminController::class, 'ordersUpdateStatus'])->name('status');
    });

    // Users Management
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [AdminController::class, 'usersIndex'])->name('index');
        Route::post('{user}/add-funds', [AdminController::class, 'usersAddFunds'])->name('add_funds');
        Route::post('{user}/ban', [AdminController::class, 'usersBan'])->name('ban');
        Route::post('{user}/unban', [AdminController::class, 'usersUnban'])->name('unban');
    });

    // Transactions Management
    Route::prefix('transactions')->name('transactions.')->group(function () {
        Route::get('/', [AdminController::class, 'transactionsIndex'])->name('index');
        Route::post('{transaction}/approve', [AdminController::class, 'transactionsApprove'])->name('approve');
        Route::post('{transaction}/reject', [AdminController::class, 'transactionsReject'])->name('reject');
    });

    // Tickets Management
    Route::prefix('tickets')->name('tickets.')->group(function () {
        Route::get('/', [AdminController::class, 'ticketsIndex'])->name('index');
        Route::post('{ticket}/reply', [AdminController::class, 'ticketsReply'])->name('reply');
        Route::post('{ticket}/close', [AdminController::class, 'ticketsClose'])->name('close');
    });

    // Analytics & Logs
    Route::get('logs/activity', [AdminController::class, 'activityLogs'])->name('logs.activity');
    Route::get('logs/payments', [AdminController::class, 'paymentLogs'])->name('logs.payments');
    Route::get('logs/providers', [AdminController::class, 'providerLogs'])->name('logs.providers');
});


    // Settings
    Route::get('/settings',                [AdminController::class, 'settings'])->name('settings');

    // Services management
    Route::get('/services',                [ServiceController::class, 'index'])->name('services.index');
});
