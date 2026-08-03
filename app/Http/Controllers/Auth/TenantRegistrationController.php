<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TenantProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public tenant self-registration: a new business creates its own
 * isolated organization (tenant + holding company + owner account) and
 * starts a trial. Staff accounts are NOT created here — owners add them
 * on the Users screen with the temporary-password flow.
 */
class TenantRegistrationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register', [
            'inviteRequired' => (bool) config('services.signup.invite_code'),
            'trialDays' => (int) config('services.signup.trial_days'),
        ]);
    }

    public function store(Request $request, TenantProvisioningService $provisioning): RedirectResponse
    {
        $request->validate([
            'organization' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'currency' => ['required', Rule::in(['USD', 'ZWG'])],
            'terms' => ['accepted'],
            'invite_code' => [
                Rule::requiredIf((bool) config('services.signup.invite_code')),
                'nullable', 'string',
                Rule::in(array_filter([config('services.signup.invite_code')])),
            ],
        ], [
            'invite_code.in' => 'That invite code is not valid.',
            'invite_code.required' => 'Registration currently requires an invite code.',
            'terms.accepted' => 'Please accept the terms of service to continue.',
        ]);

        $provisioned = $provisioning->provision(
            organizationName: $request->string('organization')->trim()->toString(),
            ownerName: $request->string('name')->trim()->toString(),
            ownerEmail: $request->string('email')->toString(),
            hashedPassword: Hash::make($request->string('password')->toString()),
            currency: $request->string('currency')->toString(),
            status: 'trial',
            trialDays: (int) config('services.signup.trial_days'),
        );

        Auth::login($provisioned['owner']);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success',
            'Welcome to '.$provisioned['tenant']->name.'! Your '
            .config('services.signup.trial_days').'-day trial has started.');
    }
}
