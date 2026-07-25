<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptInvitationRequest;
use App\Http\Requests\StoreInvitationRequest;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    /**
     * Admin: list invitations, newest first.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'invitations' => Invitation::with('inviter:id,name,email')
                ->latest()
                ->get(),
        ]);
    }

    /**
     * Admin: issue an invitation. Any earlier pending invite for the same email
     * is replaced so a fresh 7-day token is always the only valid one.
     */
    public function store(StoreInvitationRequest $request): JsonResponse
    {
        $email = strtolower($request->validated('email'));

        $invitation = DB::transaction(function () use ($email, $request) {
            Invitation::where('email', $email)->whereNull('accepted_at')->delete();

            return Invitation::create([
                'email' => $email,
                'token' => Str::random(64),
                'invited_by' => $request->user()->id,
                'expires_at' => now()->addDays(7),
            ]);
        });

        return response()->json(['invitation' => $invitation], 201);
    }

    /**
     * Admin: revoke a pending invitation.
     */
    public function destroy(Invitation $invitation): JsonResponse
    {
        $invitation->delete();

        return response()->json(['message' => 'Invitation revoked.']);
    }

    /**
     * Public: resolve a token so the accept page can show the invited email.
     */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->pendingInvitationOrFail($token);

        return response()->json([
            'email' => $invitation->email,
            'expires_at' => $invitation->expires_at,
        ]);
    }

    /**
     * Public: accept an invitation — create the user, mark the invite consumed,
     * and log the new user into the SPA session.
     */
    public function accept(AcceptInvitationRequest $request, string $token): JsonResponse
    {
        $invitation = $this->pendingInvitationOrFail($token);

        $user = DB::transaction(function () use ($invitation, $request) {
            $user = User::create([
                'name' => $request->validated('name'),
                'email' => $invitation->email,
                'password' => Hash::make($request->validated('password')),
                'is_admin' => false,
            ]);

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $user], 201);
    }

    private function pendingInvitationOrFail(string $token): Invitation
    {
        $invitation = Invitation::where('token', $token)->first();

        abort_if($invitation === null, 404, 'Invitation not found.');
        abort_if($invitation->isAccepted(), 410, 'This invitation has already been used.');
        abort_if($invitation->isExpired(), 410, 'This invitation has expired.');

        return $invitation;
    }
}
