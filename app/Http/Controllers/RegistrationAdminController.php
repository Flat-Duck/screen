<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateRegistrationSettingsRequest;
use App\Models\FeatureFlag;
use App\Models\PointTransaction;
use App\Models\User;
use App\Models\UserInvite;
use App\Services\PointRewardService;
use App\Services\RegistrationAdministrationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RegistrationAdminController extends Controller
{
    public function index(PointRewardService $rewards): View
    {
        $flag = FeatureFlag::query()->where('key', 'registration.invite_only')->first();
        $rewardSettings = [];
        foreach (PointRewardService::catalog() as $action => $details) {
            $rewardSettings[$action] = [...$details, ...$rewards->configuration($action)];
        }

        return view('registration.index', [
            'inviteOnlyEnabled' => $flag?->isActive() ?? false,
            'maturityDays' => (int) ($flag?->payload['maturity_days'] ?? 7),
            'rewardSettings' => $rewardSettings,
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

    public function update(UpdateRegistrationSettingsRequest $request, RegistrationAdministrationService $admin): RedirectResponse
    {
        $data = $request->validated();

        $admin->setInviteOnly(
            $this->user($request),
            (bool) $data['enabled'],
            (int) ($data['points_per_invite'] ?? $data['rewards'][PointRewardService::INVITER_REFERRAL]['points'] ?? 50),
            (int) $data['maturity_days'],
            $data['reason'],
            collect($data['rewards'] ?? [])->map(fn (array $value): array => [
                'enabled' => (bool) ($value['enabled'] ?? false),
                'points' => (int) $value['points'],
            ])->all(),
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
