<x-layouts::app :title="__('Registration')">
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <flux:heading size="xl">Registration & invites</flux:heading>
                <flux:text>Control whether signup requires an invite code, and tune the referral point reward.</flux:text>
            </div>
            <flux:badge :color="$inviteOnlyEnabled ? 'amber' : 'green'">Registration {{ $inviteOnlyEnabled ? 'invite-only' : 'open' }}</flux:badge>
        </div>

        @if (session('status'))
            <flux:callout variant="success">{{ session('status') }}</flux:callout>
        @endif

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><div class="text-sm text-zinc-500">Total invites redeemed</div><div class="text-2xl font-semibold">{{ number_format($totalInvites) }}</div></div>
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><div class="text-sm text-zinc-500">Matured (points paid)</div><div class="text-2xl font-semibold">{{ number_format($maturedInvites) }}</div></div>
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><div class="text-sm text-zinc-500">Total points awarded</div><div class="text-2xl font-semibold">{{ number_format($totalPointsAwarded) }}</div></div>
        </div>

        @can('manageModeration')
            <form method="POST" action="{{ route('registration.update') }}" class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700 sm:flex-row sm:items-end sm:flex-wrap">
                @csrf
                <label class="flex items-center gap-2">
                    <input type="hidden" name="enabled" value="0">
                    <input type="checkbox" name="enabled" value="1" @checked($inviteOnlyEnabled)>
                    <span>Invite-only registration</span>
                </label>
                <flux:input type="number" name="points_per_invite" label="Points per invite" min="0" max="100000" value="{{ old('points_per_invite', $pointsPerInvite) }}" class="max-w-40" />
                <flux:input type="number" name="maturity_days" label="Maturity window (days)" min="0" max="365" value="{{ old('maturity_days', $maturityDays) }}" class="max-w-40" />
                <flux:input name="reason" label="Audit reason" required class="max-w-xl" />
                <flux:button type="submit" variant="primary">Save</flux:button>
            </form>
            <flux:text class="text-sm text-zinc-500">
                A code is always redeemable and always credits its owner regardless of this toggle — it only controls whether one is <em>required</em> to sign up at all. Points are credited by a daily job once a redemption clears the maturity window and the invitee is still an active account.
            </flux:text>
        @endcan

        <section>
            <flux:heading size="lg">Top inviters</flux:heading>
            <div class="mt-3 overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-sm"><thead><tr class="text-left"><th class="p-3">User</th><th>Points balance</th></tr></thead><tbody>
                @forelse($topInviters as $user)
                    <tr class="border-t border-zinc-200 dark:border-zinc-700"><td class="p-3">{{ $user->username ? '@'.$user->username : $user->name }}</td><td>{{ number_format($user->points_balance) }}</td></tr>
                @empty
                    <tr><td colspan="2" class="p-4 text-zinc-500">No points awarded yet.</td></tr>
                @endforelse
                </tbody></table>
            </div>
        </section>
    </div>
</x-layouts::app>
