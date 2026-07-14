<div class="grid gap-4 lg:grid-cols-2">
    {{-- Ad-hoc push --}}
    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <div>
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Send a push notification</h2>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Talks to FCM directly with whatever title/body/image you set below — ignores the
                recipient's own notification settings, so use this to verify delivery itself.
            </p>
        </div>

        @if ($pushResult)
            <div class="rounded-lg bg-zinc-100 px-3 py-2 text-sm text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                {{ $pushResult }}
            </div>
        @endif

        <div class="flex gap-4 text-sm">
            <label class="flex items-center gap-1.5">
                <input type="radio" wire:model.live="pushTarget" value="user" />
                A user's devices
            </label>
            <label class="flex items-center gap-1.5">
                <input type="radio" wire:model.live="pushTarget" value="device" />
                One device
            </label>
            <label class="flex items-center gap-1.5">
                <input type="radio" wire:model.live="pushTarget" value="all" />
                All devices
            </label>
        </div>

        @if ($pushTarget === 'user')
            <div class="flex flex-col gap-1">
                <input
                    type="search"
                    wire:model.live.debounce.300ms="pushUserSearch"
                    placeholder="Search by username or name…"
                    class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                />
                <select wire:model="pushUserId" class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                    <option value="">Select a user…</option>
                    @foreach ($pushUserOptions as $option)
                        <option value="{{ $option->id }}">{{ $option->username ?? $option->name }}</option>
                    @endforeach
                </select>
                @error('pushUserId') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
            </div>
        @elseif ($pushTarget === 'device')
            <div class="flex flex-col gap-1">
                <input
                    type="search"
                    wire:model.live.debounce.300ms="pushDeviceSearch"
                    placeholder="Search by UUID, model, or owner's username…"
                    class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                />
                <select wire:model="pushDeviceId" class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                    <option value="">Select a device…</option>
                    @foreach ($pushDeviceOptions as $option)
                        <option value="{{ $option->id }}">{{ $option->manufacturer }} {{ $option->model }} — {{ $option->user?->username ?? 'no owner' }}</option>
                    @endforeach
                </select>
                @error('pushDeviceId') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Only devices with a registered push token are listed.</p>
            </div>
        @else
            <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                This broadcasts to every registered device on this environment. Be sure that's what you want.
            </p>
        @endif

        <div class="flex flex-col gap-1">
            <input
                type="text"
                wire:model="pushTitle"
                placeholder="Title"
                class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
            />
            @error('pushTitle') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
        </div>

        <div class="flex flex-col gap-1">
            <textarea
                wire:model="pushBody"
                placeholder="Body"
                rows="2"
                class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
            ></textarea>
            @error('pushBody') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
        </div>

        <div class="flex flex-col gap-1">
            <input
                type="url"
                wire:model="pushImageUrl"
                placeholder="Image URL (optional)"
                class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
            />
            @error('pushImageUrl') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
        </div>

        <button
            type="button"
            wire:click="sendPush"
            @if ($pushTarget === 'all') wire:confirm="This will send to every registered device. Continue?" @endif
            class="self-start rounded-lg bg-blue-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
        >
            Send push
        </button>
    </div>

    {{-- Real notification test --}}
    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <div>
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Send a notification</h2>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Dispatches the app's real follow/like/comment Notification classes — creates the
                in-app notification row and pushes through FcmChannel, subject to the recipient's
                own settings. Never creates a real Follow/Like/Comment row.
            </p>
        </div>

        @if ($notifResult)
            <div class="rounded-lg bg-zinc-100 px-3 py-2 text-sm text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                {{ $notifResult }}
            </div>
        @endif

        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Recipient</label>
            <input
                type="search"
                wire:model.live.debounce.300ms="notifRecipientSearch"
                placeholder="Search by username or name…"
                class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
            />
            <select wire:model="notifRecipientId" class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                <option value="">Select a user…</option>
                @foreach ($notifRecipientOptions as $option)
                    <option value="{{ $option->id }}">{{ $option->username ?? $option->name }}</option>
                @endforeach
            </select>
            @error('notifRecipientId') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Type</label>
            <select wire:model.live="notifType" class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                <option value="follow">New follower</option>
                <option value="like">Post liked</option>
                <option value="comment">Post commented</option>
            </select>
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-zinc-500 dark:text-zinc-400">
                {{ $notifType === 'follow' ? 'Follower' : ($notifType === 'like' ? 'Liker' : 'Commenter') }}
            </label>
            <input
                type="search"
                wire:model.live.debounce.300ms="notifActorSearch"
                placeholder="Search by username or name…"
                class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
            />
            <select wire:model="notifActorId" class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                <option value="">Select a user…</option>
                @foreach ($notifActorOptions as $option)
                    <option value="{{ $option->id }}">{{ $option->username ?? $option->name }}</option>
                @endforeach
            </select>
            @error('notifActorId') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
        </div>

        @if ($notifType === 'like' || $notifType === 'comment')
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Post</label>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="notifPostSearch"
                    placeholder="Search by caption or author's username…"
                    class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                />
                <select wire:model="notifPostId" class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                    <option value="">Select a post…</option>
                    @foreach ($notifPostOptions as $option)
                        <option value="{{ $option->id }}">#{{ $option->id }} — {{ $option->user?->username }} — {{ \Illuminate\Support\Str::limit($option->caption, 40) ?: '(no caption)' }}</option>
                    @endforeach
                </select>
                @error('notifPostId') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
            </div>
        @endif

        @if ($notifType === 'comment')
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Comment body</label>
                <textarea
                    wire:model="notifCommentBody"
                    rows="2"
                    class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                ></textarea>
                @error('notifCommentBody') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
            </div>
        @endif

        <button
            type="button"
            wire:click="sendTestNotification"
            class="self-start rounded-lg bg-blue-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
        >
            Send notification
        </button>
    </div>
</div>
