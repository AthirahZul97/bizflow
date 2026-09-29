<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\InvoiceStatusController;
use App\Http\Controllers\ProductController;
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

    Route::get('/expenses/{expense}/delete', [ExpenseController::class, 'delete'])->name('expenses.delete');
    Route::resource('expenses', ExpenseController::class);

    Route::prefix('reports')->name('reports.')->controller(ReportController::class)->group(function () {
        Route::get('/', 'summary')->name('summary');
        Route::get('/customers', 'customers')->name('customers');
        Route::get('/invoices', 'invoices')->name('invoices');
        Route::get('/expenses', 'expenses')->name('expenses');
    });

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
