<?php

namespace App\Http\Controllers;

use App\Models\FeatureFlag;
use App\Models\PointTransaction;
use App\Models\User;
use App\Models\UserInvite;
use App\Services\RegistrationAdministrationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RegistrationAdminController extends Controller
{
    public function index(): View
    {
        $flag = FeatureFlag::query()->where('key', 'registration.invite_only')->first();

        return view('registration.index', [
            'inviteOnlyEnabled' => $flag?->isActive() ?? false,
            'pointsPerInvite' => (int) ($flag?->payload['points_per_invite'] ?? 50),
            'maturityDays' => (int) ($flag?->payload['maturity_days'] ?? 7),
            'totalInvites' => UserInvite::query()->count(),
            'maturedInvites' => UserInvite::query()->whereNotNull('points_awarded_at')->count(),
            'totalPointsAwarded' => (int) PointTransaction::query()->sum('amount'),
            'topInviters' => User::query()
                ->where('points_balance', '>', 0)
                ->orderByDesc('points_balance')
                ->limit(10)
                ->get(['id', 'username', 'name', 'points_balance']),
        ]);
    }

    public function update(Request $request, RegistrationAdministrationService $admin): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'points_per_invite' => ['required', 'integer', 'min:0', 'max:100000'],
            'maturity_days' => ['required', 'integer', 'min:0', 'max:365'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $admin->setInviteOnly(
            $this->user($request),
            (bool) $data['enabled'],
            (int) $data['points_per_invite'],
            (int) $data['maturity_days'],
            $data['reason'],
        );

        return back()->with('status', 'Registration settings updated.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
