<?php

namespace App\Http\Controllers;

use App\Http\Requests\BusinessProfileRequest;
use App\Support\CurrentBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The current business's profile: the seller details printed on invoices.
 * There is no business ID in the URL; it is always the current business.
 */
class BusinessProfileController extends Controller
{
    public function __construct(private readonly CurrentBusiness $currentBusiness) {}

    public function edit(): View
    {
        $business = $this->currentBusiness->get();
        Gate::authorize('update', $business);

        return view('business.profile', ['business' => $business]);
    }

    public function update(BusinessProfileRequest $request): RedirectResponse
    {
        // BusinessProfileRequest has already authorized the update.
        $this->currentBusiness->get()->update($request->validated());

        return redirect()->route('business.profile.edit')
            ->with('status', 'Business profile updated.');
    }
}
