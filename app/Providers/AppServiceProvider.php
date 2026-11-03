<?php

namespace App\Providers;

use App\Billing\BillingProvider;
use App\Billing\EntitlementService;
use App\Billing\ManualBillingProvider;
use App\Billing\SubscriptionNotice;
use App\Enums\BusinessRole;
use App\Mail\GuardedMailManager;
use App\Ocr\Providers\DisabledReceiptOcrProvider;
use App\Ocr\Providers\FakeReceiptOcrProvider;
use App\Ocr\ReceiptOcrProvider;
use App\Support\CurrentBusiness;
use App\Support\DemoMode;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Every mail transport, however it is selected, passes the demo-mode delivery guard.
        $this->app->extend('mail.manager', fn ($manager, $app) => new GuardedMailManager($app));

        // One per request (or queued job): the tenant the HTTP layer works in.
        $this->app->scoped(CurrentBusiness::class);

        // Memoizes entitlements for one request (or queued job) only; there is no other cache.
        $this->app->scoped(EntitlementService::class);

        // The only provider today does not take payments; a real one is a new case here.
        $this->app->bind(BillingProvider::class, fn () => match (config('billing.provider')) {
            'manual' => new ManualBillingProvider,
            default => throw new \InvalidArgumentException('Unknown billing provider ['.config('billing.provider').'].'),
        });

        // The OCR provider is the one seam to any OCR service. Only the demo provider exists: no
        // real provider has been selected or integrated, and no receipt leaves the application.
        $this->app->bind(ReceiptOcrProvider::class, fn () => match (config('ocr.driver')) {
            // Invented data must never be shown as a real reading: outside demo mode a production
            // deployment refuses the demo provider ("bizflow:preflight" reports it first).
            'fake' => app()->isProduction() && ! DemoMode::enabled()
                ? throw new LogicException('OCR_DRIVER=fake is only allowed in demo mode in production. Use OCR_DRIVER=none.')
                : new FakeReceiptOcrProvider,
            'none' => new DisabledReceiptOcrProvider,
            default => throw new \InvalidArgumentException('Unknown OCR driver ['.config('ocr.driver').'].'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // The last line of defence for outbound email: a message is refused, before any transport
        // sees it, when the deployment does not allow delivery (demo mode on a real transport).
        // Returning false cancels the send, and Mailer::send() then returns null: callers must not
        // record that as sent (SendInvoiceEmail does not).
        Event::listen(MessageSending::class, fn (MessageSending $event) => DemoMode::deliveryAllowed() ? null : false);

        // Remember, in the session, which password it was signed in with, the moment it is signed in.
        // AuthenticateSession (web group) compares that with the account's current password on every
        // request and ends the session when they differ, which is how a password reset signs out the
        // other devices. Left to itself it records the hash on the first request AFTER login, so a
        // session that logged in and was not used again before the reset would survive it.
        Event::listen(Login::class, function (Login $event): void {
            $request = request();

            if ($request->hasSession() && $event->user->getAuthPassword()) {
                $request->session()->put(
                    'password_hash_'.$event->guard,
                    auth()->guard($event->guard)->hashPasswordForCookie($event->user->getAuthPassword()),
                );
            }
        });

        // Per client IP, on top of the per-email-and-IP lockout in LoginRequest: that one stops
        // guessing one account's password, this one stops trying many accounts from one address.
        // Generous enough that a shared office or interview network is not locked out by normal use.
        RateLimiter::for('login-ip', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        // The subscription banner and the owner's Billing link, on every page of the main layout.
        // A page without a signed-in user or a resolvable business (login, error pages) shows neither.
        View::composer('layouts.app', function (\Illuminate\View\View $view) {
            if (! auth()->check()) {
                return;
            }

            try {
                $business = app(CurrentBusiness::class)->get();
            } catch (AuthenticationException|HttpExceptionInterface|LogicException) {
                return;
            }

            $view->with([
                'subscriptionNotice' => SubscriptionNotice::for(app(EntitlementService::class)->for($business)),
                // Display only (the policy is what authorizes): read the role CurrentBusiness already
                // loaded instead of asking the database again on every page.
                'showBillingNav' => $business->pivot?->getRawOriginal('role') === BusinessRole::Owner->value,
            ]);
        });
    }
}
