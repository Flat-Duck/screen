<x-layouts::app :title="__('Registration')">
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <flux:heading size="xl">Registration & invites</flux:heading>
                <flux:text>Control invitation requirements and configure point rewards for supported actions.</flux:text>
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
            <form method="POST" action="{{ route('registration.update') }}" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                @csrf
                <div class="flex flex-wrap items-end gap-3">
                    <label class="flex items-center gap-2 pb-2">
                        <input type="hidden" name="enabled" value="0">
                        <input type="checkbox" name="enabled" value="1" @checked($inviteOnlyEnabled)>
                        <span>Invite-only registration</span>
                    </label>
                    <flux:input type="number" name="maturity_days" label="Maturity window (days)" min="0" max="365" value="{{ old('maturity_days', $maturityDays) }}" class="max-w-40" />
                    <flux:input name="reason" label="Audit reason" required class="max-w-xl" />
                </div>
            <flux:text class="text-sm text-zinc-500">
                A code remains redeemable regardless of the invite-only toggle. Invitation owner points are credited after the maturity window while the new account remains active.
            </flux:text>

            <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="mb-4">
                    <flux:heading size="lg">Points rewards</flux:heading>
                    <flux:text>Choose the point amount and whether each supported action earns points. Disabled rewards are not credited.</flux:text>
                </div>
                <div class="grid gap-3 lg:grid-cols-2">
                    @foreach($rewardSettings as $action => $reward)
                        <div class="flex flex-wrap items-end justify-between gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                            <div class="min-w-48 flex-1">
                                <div class="font-medium">{{ $reward['label'] }}</div>
                                <div class="mt-1 text-sm text-zinc-500">{{ $reward['description'] }}</div>
                                <label class="mt-3 flex items-center gap-2 text-sm">
                                    <input type="hidden" name="rewards[{{ $action }}][enabled]" value="0">
                                    <input type="checkbox" name="rewards[{{ $action }}][enabled]" value="1" @checked(old("rewards.{$action}.enabled", $reward['enabled']))>
                                    <span>Reward active</span>
                                </label>
                            </div>
                            <flux:input type="number" name="rewards[{{ $action }}][points]" label="Points" min="0" max="100000" value="{{ old("rewards.{$action}.points", $reward['points']) }}" class="max-w-36" />
                        </div>
                    @endforeach
                </div>
            </section>
            <div class="flex justify-end"><flux:button type="submit" variant="primary">Save all settings</flux:button></div>
            </form>
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
