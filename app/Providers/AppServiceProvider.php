<?php

namespace App\Providers;

use App\Billing\BillingProvider;
use App\Billing\EntitlementService;
use App\Billing\ManualBillingProvider;
use App\Billing\SubscriptionNotice;
use App\Enums\BusinessRole;
use App\Ocr\Providers\DisabledReceiptOcrProvider;
use App\Ocr\Providers\FakeReceiptOcrProvider;
use App\Ocr\ReceiptOcrProvider;
use App\Support\CurrentBusiness;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Pagination\Paginator;
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
            'fake' => new FakeReceiptOcrProvider,
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
