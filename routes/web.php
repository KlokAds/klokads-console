<?php

use App\Http\Controllers\CashflowController;
use App\Http\Controllers\ClientCardController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\MoneyController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentLinkController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\PersonalDashboardController;
use App\Http\Controllers\PersonalInvoiceController;
use App\Http\Controllers\PersonalLockController;
use App\Http\Controllers\PlanningController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\RecurringPlanController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SpendBillingController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

// cron-job.org pings this every few minutes to send the morning / night reminders.
Route::get('/reminders/ping/{token}', [NotificationController::class, 'ping'])->middleware('throttle:30,1')->name('reminders.ping');

// An Android SMS-forwarder app posts bank / wallet SMS here (secret token in the URL).
Route::post('/inbox/sms/{token}', [InboxController::class, 'sms'])->middleware('throttle:120,1')->name('inbox.sms');

Route::get('/card-saved', [ClientCardController::class, 'saved'])->name('cards.saved');

// Client-facing invoice / receipt pages (secret link per invoice) and add-card page (signed link).
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/card/{client}', [ClientCardController::class, 'publicPage'])->middleware('signed')->name('public.card');
    Route::get('/i/{token}', [PublicInvoiceController::class, 'show'])->name('public.invoice');
    // My personal invoices (client pays by bank / bKash / Nagad / Rocket / Upay / Bangla QR).
    Route::get('/pi/{token}', [PersonalInvoiceController::class, 'public'])->name('personal.invoice.public');
    Route::get('/pi/{token}/pdf', [PersonalInvoiceController::class, 'publicPdf'])->name('personal.invoice.public.pdf');
    Route::post('/pi/{token}/paid', [PersonalInvoiceController::class, 'report'])->middleware('throttle:6,1')->name('personal.invoice.public.report');
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
    Route::get('/notifications', [NotificationController::class, 'index'])->middleware('personal')->name('notifications.index');
    Route::post('/notifications/subscribe', [NotificationController::class, 'subscribe'])->middleware('personal')->name('notifications.subscribe');
    Route::put('/notifications/{subscription}', [NotificationController::class, 'update'])->middleware('personal')->name('notifications.update');
    Route::delete('/notifications/{subscription}', [NotificationController::class, 'destroy'])->middleware('personal')->name('notifications.destroy');
    Route::post('/notifications/{subscription}/test', [NotificationController::class, 'test'])->middleware('personal')->name('notifications.test');
    Route::get('/search', SearchController::class)->middleware('throttle:120,1')->name('search');
    Route::get('/personal/unlock', [PersonalLockController::class, 'show'])->name('personal.unlock');
    Route::post('/personal/unlock', [PersonalLockController::class, 'unlock'])->middleware('throttle:20,1')->name('personal.unlock.submit');
    Route::post('/personal/lock', [PersonalLockController::class, 'lock'])->name('personal.lock');
    Route::post('/personal/pin', [PersonalLockController::class, 'save'])->middleware('personal')->name('personal.pin');
    Route::redirect('/me', '/personal');
    Route::get('/personal', PersonalDashboardController::class)->middleware('personal')->name('personal.dashboard');
    Route::post('/personal/budgets', [PlanningController::class, 'saveBudgets'])->middleware('personal')->name('personal.budgets');
    Route::post('/personal/goals', [PlanningController::class, 'storeGoal'])->middleware('personal')->name('personal.goals.store');
    Route::put('/personal/goals/{goal}', [PlanningController::class, 'updateGoal'])->middleware('personal')->name('personal.goals.update');
    Route::delete('/personal/goals/{goal}', [PlanningController::class, 'destroyGoal'])->middleware('personal')->name('personal.goals.destroy');
    Route::post('/personal/bills', [PlanningController::class, 'storeBill'])->middleware('personal')->name('personal.bills.store');
    Route::put('/personal/bills/{bill}', [PlanningController::class, 'updateBill'])->middleware('personal')->name('personal.bills.update');
    Route::delete('/personal/bills/{bill}', [PlanningController::class, 'destroyBill'])->middleware('personal')->name('personal.bills.destroy');
    Route::post('/personal/bills/{bill}/pay', [PlanningController::class, 'payBill'])->middleware('personal')->name('personal.bills.pay');
    Route::get('/personal/invoices', [PersonalInvoiceController::class, 'index'])->middleware('personal')->name('personal.invoices.index');
    Route::post('/personal/invoices', [PersonalInvoiceController::class, 'store'])->middleware('personal')->name('personal.invoices.store');
    Route::put('/personal/invoices/{invoice}', [PersonalInvoiceController::class, 'update'])->middleware('personal')->name('personal.invoices.update');
    Route::delete('/personal/invoices/{invoice}', [PersonalInvoiceController::class, 'destroy'])->middleware('personal')->name('personal.invoices.destroy');
    Route::post('/personal/invoices/{invoice}/void', [PersonalInvoiceController::class, 'void'])->middleware('personal')->name('personal.invoices.void');
    Route::post('/personal/invoices/{invoice}/send', [PersonalInvoiceController::class, 'send'])->middleware('personal')->name('personal.invoices.send');
    Route::post('/personal/invoices/{invoice}/payments', [PersonalInvoiceController::class, 'pay'])->middleware('personal')->name('personal.invoices.pay');
    Route::post('/personal/invoices/{invoice}/dismiss-report', [PersonalInvoiceController::class, 'dismissReport'])->middleware('personal')->name('personal.invoices.dismiss');
    Route::get('/personal/invoices/{invoice}/pdf', [PersonalInvoiceController::class, 'pdf'])->middleware('personal')->name('personal.invoices.pdf');
    Route::delete('/personal/invoice-payments/{payment}', [PersonalInvoiceController::class, 'destroyPayment'])->middleware('personal')->name('personal.invoices.payments.destroy');
    Route::post('/personal/clients', [PersonalInvoiceController::class, 'storeClient'])->middleware('personal')->name('personal.clients.store');
    Route::put('/personal/clients/{client}', [PersonalInvoiceController::class, 'updateClient'])->middleware('personal')->name('personal.clients.update');
    Route::delete('/personal/clients/{client}', [PersonalInvoiceController::class, 'destroyClient'])->middleware('personal')->name('personal.clients.destroy');
    Route::post('/personal/recurring', [PersonalInvoiceController::class, 'storeRecurring'])->middleware('personal')->name('personal.recurring.store');
    Route::put('/personal/recurring/{recurring}', [PersonalInvoiceController::class, 'updateRecurring'])->middleware('personal')->name('personal.recurring.update');
    Route::delete('/personal/recurring/{recurring}', [PersonalInvoiceController::class, 'destroyRecurring'])->middleware('personal')->name('personal.recurring.destroy');
    Route::post('/personal/invoice-profile', [PersonalInvoiceController::class, 'saveProfile'])->middleware('personal')->name('personal.invoices.profile');
    Route::get('/personal/inbox', [InboxController::class, 'index'])->middleware('personal')->name('personal.inbox.index');
    Route::post('/personal/inbox/{message}/add', [InboxController::class, 'add'])->middleware('personal')->name('personal.inbox.add');
    Route::post('/personal/inbox/{message}/ignore', [InboxController::class, 'ignore'])->middleware('personal')->name('personal.inbox.ignore');
    Route::post('/personal/inbox/{message}/undo', [InboxController::class, 'undo'])->middleware('personal')->name('personal.inbox.undo');
    Route::post('/personal/inbox-imap', [InboxController::class, 'saveImap'])->middleware('personal')->name('personal.inbox.imap');
    Route::post('/personal/inbox-check', [InboxController::class, 'checkNow'])->middleware('personal')->name('personal.inbox.check');
    Route::post('/personal/inbox-test', [InboxController::class, 'test'])->middleware('personal')->name('personal.inbox.test');
    Route::get('/personal/loans', [LoanController::class, 'index'])->middleware('personal')->name('personal.loans.index');
    Route::post('/personal/loans', [LoanController::class, 'store'])->middleware('personal')->name('personal.loans.store');
    Route::put('/personal/loans/{loan}', [LoanController::class, 'update'])->middleware('personal')->name('personal.loans.update');
    Route::delete('/personal/loans/{loan}', [LoanController::class, 'destroy'])->middleware('personal')->name('personal.loans.destroy');
    Route::post('/personal/loans/{loan}/payments', [LoanController::class, 'pay'])->middleware('personal')->name('personal.loans.pay');
    Route::delete('/personal/loan-payments/{payment}', [LoanController::class, 'destroyPayment'])->middleware('personal')->name('personal.loans.payments.destroy');

    Route::resource('clients', ClientController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::post('/clients/{client}/cards/link', [ClientCardController::class, 'link'])->name('clients.cards.link');
    Route::post('/clients/{client}/cards/enter', [ClientCardController::class, 'enter'])->name('clients.cards.enter');
    Route::post('/clients/{client}/charge', [ClientCardController::class, 'chargeNow'])->name('clients.charge');
    Route::post('/clients/{client}/meta', [SpendBillingController::class, 'update'])->name('clients.meta');
    Route::post('/clients/{client}/meta/sync', [SpendBillingController::class, 'sync'])->name('clients.meta.sync');
    Route::post('/clients/{client}/meta/charge', [SpendBillingController::class, 'charge'])->name('clients.meta.charge');
    Route::post('/clients/{client}/meta/top-up', [SpendBillingController::class, 'topUp'])->name('clients.meta.topup');
    Route::post('/clients/{client}/meta/adjust', [SpendBillingController::class, 'adjust'])->name('clients.meta.adjust');
    Route::post('/settings/meta', [SpendBillingController::class, 'saveToken'])->name('settings.meta');
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
    Route::get('/money/export', [MoneyController::class, 'export'])->name('money.export');
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
