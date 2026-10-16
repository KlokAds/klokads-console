<?php

use App\Http\Controllers\CashflowController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentLinkController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecurringPlanController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

Route::prefix('install')->name('install.')->group(function () {
    Route::get('/', [InstallController::class, 'show'])->name('show');
    Route::post('/code', [InstallController::class, 'verifyCode'])->middleware('throttle:10,1')->name('code');
    Route::post('/database', [InstallController::class, 'database'])->name('database');
    Route::post('/admin', [InstallController::class, 'admin'])->name('admin');
    Route::post('/business', [InstallController::class, 'business'])->name('business');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::resource('clients', ClientController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
    Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
    Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
    Route::post('/invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])->name('invoices.mark-paid');
    Route::post('/invoices/{invoice}/refresh', [InvoiceController::class, 'refresh'])->name('invoices.refresh');

    Route::get('/recurring', [RecurringPlanController::class, 'index'])->name('recurring.index');
    Route::post('/recurring', [RecurringPlanController::class, 'store'])->name('recurring.store');
    Route::post('/recurring/{plan}/cancel', [RecurringPlanController::class, 'cancel'])->name('recurring.cancel');

    Route::get('/payment-links', [PaymentLinkController::class, 'index'])->name('payment-links.index');
    Route::post('/payment-links', [PaymentLinkController::class, 'store'])->name('payment-links.store');
    Route::post('/payment-links/{paymentLink}/toggle', [PaymentLinkController::class, 'toggle'])->name('payment-links.toggle');

    Route::resource('expenses', ExpenseController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::get('/cashflow', [CashflowController::class, 'index'])->name('cashflow.index');
    Route::get('/cashflow/export', [CashflowController::class, 'export'])->name('cashflow.export');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::post('/settings/stripe', [SettingsController::class, 'updateStripe'])->name('settings.stripe');
    Route::delete('/settings/stripe', [SettingsController::class, 'disconnect'])->name('settings.stripe.disconnect');
    Route::post('/settings/business', [SettingsController::class, 'updateBusiness'])->name('settings.business');
    Route::post('/settings/sync', [SettingsController::class, 'sync'])->name('settings.sync');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
