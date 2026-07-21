<div class="flex flex-col gap-4">
    @if($flashMessage)<div class="rounded-lg bg-zinc-100 p-3 text-sm dark:bg-zinc-800">{{ $flashMessage }}</div>@endif
    <div class="rounded-xl border p-4 dark:border-zinc-700">
        <h1 class="text-xl font-semibold">{{ $user->username }} <span class="text-sm font-normal text-zinc-500">#{{ $user->id }}</span></h1>
        <p class="text-sm text-zinc-500">{{ $user->name }} · {{ $user->email }} · {{ $user->moderation_state->value }} · {{ $user->account_visibility->value }}</p>
        <div class="mt-3 grid gap-2 text-sm sm:grid-cols-3 lg:grid-cols-6"><span>{{ $user->posts_count }} posts</span><span>{{ $user->followers_count }} followers</span><span>{{ $user->following_count }} following</span><span>{{ $user->devices_count }} devices</span><span>{{ $user->device_sessions_count }} sessions</span><span>{{ $user->social_accounts_count }} connected</span></div>
    </div>
    @can('manageModeration')
        <div class="grid gap-3 rounded-xl border p-4 dark:border-zinc-700 md:grid-cols-4">
            <select wire:model="restrictionType" class="rounded-lg border p-2 dark:bg-zinc-900"><option value="posting">Posting</option><option value="commenting">Commenting</option><option value="messaging">Messaging</option><option value="recommendation">Recommendation</option><option value="login">Login</option></select>
            <input wire:model="durationDays" type="number" min="1" max="3650" placeholder="Days; blank = permanent" class="rounded-lg border p-2 dark:bg-zinc-900" />
            <input wire:model="restrictionReason" placeholder="Required reason" class="rounded-lg border p-2 dark:bg-zinc-900" />
            <button wire:click="createRestriction" class="rounded-lg bg-red-700 p-2 text-white">Add restriction</button>
            @error('restrictionReason')<span class="text-sm text-red-600">{{ $message }}</span>@enderror
        </div>
    @endcan
    <section class="rounded-xl border p-4 dark:border-zinc-700"><h2 class="font-semibold">Restrictions</h2>
        @forelse($user->restrictions->sortByDesc('id') as $restriction)<div class="mt-3 flex flex-wrap items-center gap-3 text-sm"><strong>{{ $restriction->type->value }}</strong><span>{{ $restriction->reason }}</span><span>{{ $restriction->revoked_at ? 'Revoked' : ($restriction->ends_at?->isPast() ? 'Expired' : 'Active') }}</span><span>{{ $restriction->ends_at?->toDateTimeString() ?? 'Permanent' }}</span>@can('manageModeration')@if(!$restriction->revoked_at)<button wire:click="extendRestriction({{ $restriction->id }}, 7)" class="text-blue-600">+7 days</button><button wire:click="revokeRestriction({{ $restriction->id }}, 'Revoked from user detail')" class="text-red-600">Revoke</button>@endif@endcan</div>@empty<p class="mt-2 text-sm text-zinc-500">No restrictions.</p>@endforelse
    </section>
    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl border p-4 dark:border-zinc-700"><h2 class="font-semibold">Devices</h2>@foreach($user->devices as $device)<div class="mt-2 text-sm">{{ $device->manufacturer }} {{ $device->model }} · {{ $device->os_name }} {{ $device->os_version }} · {{ $device->last_seen_at }}</div>@endforeach</section>
        <section class="rounded-xl border p-4 dark:border-zinc-700"><h2 class="font-semibold">Recent sessions</h2>@foreach($user->deviceSessions as $session)<div class="mt-2 text-sm">{{ $session->login_method->value }} · {{ $session->started_at }} · {{ $session->ended_at ? 'Ended' : 'Active' }}</div>@endforeach</section>
        <section class="rounded-xl border p-4 dark:border-zinc-700"><h2 class="font-semibold">Reports against user</h2>@foreach($receivedReports as $report)<div class="mt-2 text-sm">{{ $report->reason }} · {{ $report->status }} · {{ $report->details }}</div>@endforeach</section>
        <section class="rounded-xl border p-4 dark:border-zinc-700"><h2 class="font-semibold">Moderation history</h2>@foreach($moderationHistory as $event)<div class="mt-2 text-sm">{{ $event->action }} · {{ $event->reason }} · {{ $event->created_at }}</div>@endforeach</section>
        <section class="rounded-xl border p-4 dark:border-zinc-700"><h2 class="font-semibold">Recent screenshots</h2>@foreach($user->posts as $post)<div class="mt-2 text-sm"><a class="text-blue-600" href="{{ route('moderation.content.show', $post->id) }}">#{{ $post->id }}</a> · {{ $post->trashed() ? 'Removed' : 'Active' }} · {{ $post->caption }}</div>@endforeach</section>
        <section class="rounded-xl border p-4 dark:border-zinc-700"><h2 class="font-semibold">Warnings</h2>@foreach($warnings as $warning)<div class="mt-2 text-sm">{{ $warning->reason }} · {{ $warning->created_at }}</div>@endforeach</section>
        <section class="rounded-xl border p-4 dark:border-zinc-700"><h2 class="font-semibold">Connected accounts</h2>@foreach($user->socialAccounts as $account)<div class="mt-2 text-sm">{{ $account->provider }}</div>@endforeach</section>
    </div>
    @can('manageUserSupport')<section class="rounded-xl border p-4 dark:border-zinc-700"><h2 class="font-semibold">Support notes</h2><textarea wire:model="supportNote" class="mt-2 w-full rounded-lg border p-2 dark:bg-zinc-900" placeholder="Internal support note"></textarea><button wire:click="addSupportNote" class="mt-2 rounded-lg bg-zinc-800 px-3 py-2 text-white">Add note</button>@foreach($user->supportNotes->sortByDesc('id') as $note)<div class="mt-2 text-sm">{{ $note->body }} — {{ $note->author?->username }}</div>@endforeach</section>@endcan
</div>
