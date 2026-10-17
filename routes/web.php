<?php

use App\Http\Controllers\CashflowController;
use App\Http\Controllers\ClientCardController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MoneyController;
use App\Http\Controllers\PaymentLinkController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\RecurringPlanController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

Route::get('/card-saved', [ClientCardController::class, 'saved'])->name('cards.saved');

// Client-facing invoice / receipt pages (secret link per invoice) and add-card page (signed link).
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/card/{client}', [ClientCardController::class, 'publicPage'])->middleware('signed')->name('public.card');
    Route::get('/i/{token}', [PublicInvoiceController::class, 'show'])->name('public.invoice');
    Route::get('/i/{token}/pdf', [PublicInvoiceController::class, 'pdf'])->name('public.invoice.pdf');
});

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
    Route::post('/clients/{client}/cards/link', [ClientCardController::class, 'link'])->name('clients.cards.link');
    Route::post('/clients/{client}/cards/enter', [ClientCardController::class, 'enter'])->name('clients.cards.enter');
    Route::post('/clients/{client}/charge', [ClientCardController::class, 'chargeNow'])->name('clients.charge');
    Route::post('/clients/{client}/cards/{paymentMethod}/default', [ClientCardController::class, 'makeDefault'])->name('clients.cards.default');
    Route::delete('/clients/{client}/cards/{paymentMethod}', [ClientCardController::class, 'destroy'])->name('clients.cards.destroy');
    Route::post('/invoices/{invoice}/charge', [ClientCardController::class, 'charge'])->name('invoices.charge');

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
    Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
    Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
    Route::post('/invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])->name('invoices.mark-paid');
    Route::post('/invoices/{invoice}/refresh', [InvoiceController::class, 'refresh'])->name('invoices.refresh');
    Route::get('/invoices/{invoice}/document', [DocumentController::class, 'show'])->name('invoices.document');
    Route::post('/invoices/{invoice}/template', [DocumentController::class, 'setTemplate'])->name('invoices.template');
    Route::get('/settings/templates/{template}/preview', [DocumentController::class, 'preview'])->name('settings.templates.preview');

    Route::get('/recurring', [RecurringPlanController::class, 'index'])->name('recurring.index');
    Route::post('/recurring', [RecurringPlanController::class, 'store'])->name('recurring.store');
    Route::post('/recurring/{plan}/cancel', [RecurringPlanController::class, 'cancel'])->name('recurring.cancel');

    Route::get('/payment-links', [PaymentLinkController::class, 'index'])->name('payment-links.index');
    Route::post('/payment-links', [PaymentLinkController::class, 'store'])->name('payment-links.store');
    Route::post('/payment-links/{paymentLink}/toggle', [PaymentLinkController::class, 'toggle'])->name('payment-links.toggle');

    Route::resource('expenses', ExpenseController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::get('/money', [MoneyController::class, 'index'])->name('money.index');
    Route::post('/money', [MoneyController::class, 'store'])->name('money.store');
    Route::put('/money/{transaction}', [MoneyController::class, 'update'])->name('money.update');
    Route::delete('/money/{transaction}', [MoneyController::class, 'destroy'])->name('money.destroy');
    Route::post('/money/accounts', [MoneyController::class, 'storeAccount'])->name('money.accounts.store');
    Route::put('/money/accounts/{account}', [MoneyController::class, 'updateAccount'])->name('money.accounts.update');
    Route::delete('/money/accounts/{account}', [MoneyController::class, 'destroyAccount'])->name('money.accounts.destroy');

    Route::get('/payouts', [PayoutController::class, 'index'])->name('payouts.index');
    Route::post('/payouts/bank', [PayoutController::class, 'updateBank'])->name('payouts.bank');
    Route::post('/payouts', [PayoutController::class, 'store'])->name('payouts.store');
    Route::post('/payouts/schedule', [PayoutController::class, 'updateSchedule'])->name('payouts.schedule');

    Route::get('/cashflow', [CashflowController::class, 'index'])->name('cashflow.index');
    Route::get('/cashflow/export', [CashflowController::class, 'export'])->name('cashflow.export');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::post('/settings/stripe', [SettingsController::class, 'updateStripe'])->name('settings.stripe');
    Route::delete('/settings/stripe', [SettingsController::class, 'disconnect'])->name('settings.stripe.disconnect');
    Route::post('/settings/business', [SettingsController::class, 'updateBusiness'])->name('settings.business');
    Route::post('/settings/pricing', [SettingsController::class, 'updatePricing'])->name('settings.pricing');
    Route::post('/settings/descriptors', [SettingsController::class, 'updateDescriptors'])->name('settings.descriptors');
    Route::post('/settings/gst', [SettingsController::class, 'updateGst'])->name('settings.gst');
    Route::post('/settings/email', [SettingsController::class, 'updateEmail'])->name('settings.email');
    Route::post('/settings/email/test', [SettingsController::class, 'testEmail'])->name('settings.email.test');
    Route::post('/settings/templates', [SettingsController::class, 'updateTemplates'])->name('settings.templates');
    Route::post('/settings/sync', [SettingsController::class, 'sync'])->name('settings.sync');
    Route::post('/settings/auto-sync', [SettingsController::class, 'autoSync'])->name('settings.auto-sync');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
