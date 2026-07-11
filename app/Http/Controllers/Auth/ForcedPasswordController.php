<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ForcedPasswordController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Auth/ForcePasswordChange');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'different:current_password', Password::defaults(), 'confirmed'],
        ]);

        $user = $request->user();
        $currentSessionId = $request->session()->getId();

        DB::transaction(function () use ($user, $validated, $currentSessionId) {
            $user->forceFill([
                'password' => Hash::make($validated['password']),
                'must_change_password' => false,
                'remember_token' => Str::random(60),
            ])->save();

            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->where('id', '!=', $currentSessionId)
                    ->delete();
            }

            AuditLog::record('initial_password_changed', $user);
        });

        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Your password has been changed.');
    }
}
