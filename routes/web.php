<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BusinessProfileController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExpenseReceiptController;
use App\Http\Controllers\ExpenseReceiptFileController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceEmailController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\InvoiceStatusController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RecurringInvoiceController;
use App\Http\Controllers\RecurringInvoiceStatusController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', HealthCheckController::class)->name('health');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:6,1');

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    // Everything that reads or writes business data runs in the current business, and in a
    // read-only subscription only reads (plus the allow-listed billing actions) get through.
    Route::middleware(['business', 'subscription.writable'])->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        Route::get('/customers/{customer}/delete', [CustomerController::class, 'delete'])->name('customers.delete');
        Route::resource('customers', CustomerController::class);

        Route::get('/products/{product}/delete', [ProductController::class, 'delete'])->name('products.delete');
        Route::resource('products', ProductController::class);

        Route::get('/invoices/{invoice}/delete', [InvoiceController::class, 'delete'])->name('invoices.delete');
        Route::resource('invoices', InvoiceController::class);

        Route::post('/invoices/{invoice}/issue', [InvoiceStatusController::class, 'issue'])->name('invoices.issue');
        Route::post('/invoices/{invoice}/mark-paid', [InvoiceStatusController::class, 'markPaid'])->name('invoices.mark-paid');
        Route::post('/invoices/{invoice}/mark-unpaid', [InvoiceStatusController::class, 'markUnpaid'])->name('invoices.mark-unpaid');
        Route::get('/invoices/{invoice}/cancel', [InvoiceStatusController::class, 'confirmCancel'])->name('invoices.cancel.confirm');
        Route::post('/invoices/{invoice}/cancel', [InvoiceStatusController::class, 'cancel'])->name('invoices.cancel');
        Route::get('/invoices/{invoice}/pdf', InvoicePdfController::class)->name('invoices.pdf');
        Route::get('/invoices/{invoice}/email', [InvoiceEmailController::class, 'create'])->name('invoices.email.create');
        Route::post('/invoices/{invoice}/email', [InvoiceEmailController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('invoices.email.store');

        Route::get('/recurring-invoices/{recurring_invoice}/delete', [RecurringInvoiceController::class, 'delete'])->name('recurring-invoices.delete');
        Route::resource('recurring-invoices', RecurringInvoiceController::class);

        Route::post('/recurring-invoices/{recurring_invoice}/pause', [RecurringInvoiceStatusController::class, 'pause'])->name('recurring-invoices.pause');
        Route::post('/recurring-invoices/{recurring_invoice}/resume', [RecurringInvoiceStatusController::class, 'resume'])->name('recurring-invoices.resume');
        Route::get('/recurring-invoices/{recurring_invoice}/cancel', [RecurringInvoiceStatusController::class, 'confirmCancel'])->name('recurring-invoices.cancel.confirm');
        Route::post('/recurring-invoices/{recurring_invoice}/cancel', [RecurringInvoiceStatusController::class, 'cancel'])->name('recurring-invoices.cancel');
        Route::post('/recurring-invoices/{recurring_invoice}/generate', [RecurringInvoiceStatusController::class, 'generate'])
            ->middleware('throttle:6,1')
            ->name('recurring-invoices.generate');

        Route::get('/expenses/{expense}/delete', [ExpenseController::class, 'delete'])->name('expenses.delete');
        Route::resource('expenses', ExpenseController::class);

        // Receipt scanning: upload, status, review and confirm. Receipts are private files; the
        // only way to read one is the policy-checked file route. Confirming is the only thing
        // that creates an expense.
        Route::get('/expense-receipts/{expense_receipt}/delete', [ExpenseReceiptController::class, 'delete'])->name('expense-receipts.delete');
        Route::get('/expense-receipts/{expense_receipt}/file', ExpenseReceiptFileController::class)->name('expense-receipts.file');
        Route::post('/expense-receipts', [ExpenseReceiptController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('expense-receipts.store');
        Route::post('/expense-receipts/{expense_receipt}/confirm', [ExpenseReceiptController::class, 'confirm'])
            ->middleware('throttle:12,1')
            ->name('expense-receipts.confirm');
        Route::post('/expense-receipts/{expense_receipt}/retry', [ExpenseReceiptController::class, 'retry'])
            ->middleware('throttle:10,1')
            ->name('expense-receipts.retry');
        Route::post('/expense-receipts/{expense_receipt}/manual', [ExpenseReceiptController::class, 'manual'])->name('expense-receipts.manual');
        Route::resource('expense-receipts', ExpenseReceiptController::class)->only(['index', 'create', 'show', 'destroy']);

        Route::prefix('reports')->name('reports.')->controller(ReportController::class)->group(function () {
            Route::get('/', 'summary')->name('summary');
            Route::get('/customers', 'customers')->name('customers');
            Route::get('/invoices', 'invoices')->name('invoices');
            Route::get('/expenses', 'expenses')->name('expenses');
        });

        // Billing: the current business's subscription. No subscription or business ID is ever
        // in a URL. The writes here are the only ones a read-only business may make (see
        // EnsureSubscriptionWritable::READ_ONLY_ALLOWED_ROUTES).
        Route::prefix('billing')->name('billing.')->controller(BillingController::class)->group(function () {
            Route::get('/', 'show')->name('show');
            Route::get('/plans', 'plans')->name('plans');
            Route::post('/plan', 'change')->name('change');
            Route::get('/cancel', 'confirmCancel')->name('cancel.confirm');
            Route::post('/cancel', 'cancel')->name('cancel');
            Route::post('/resume', 'resume')->name('resume');
        });

        Route::get('/business/profile', [BusinessProfileController::class, 'edit'])->name('business.profile.edit');
        Route::put('/business/profile', [BusinessProfileController::class, 'update'])->name('business.profile.update');
    });

    // Outside the business group so an account without a business can still log out.
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
