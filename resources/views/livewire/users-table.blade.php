<div class="flex flex-col gap-4">
    @if ($flashMessage)
        <div class="rounded-lg bg-zinc-100 px-3 py-2 text-sm text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
            {{ $flashMessage }}
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Search by username, name, or email…"
                class="w-full max-w-sm rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
            />
            <label class="flex items-center gap-1.5 text-sm text-zinc-600 dark:text-zinc-300">
                <input type="checkbox" wire:model.live="showDeleted" />
                Show deleted
            </label>
            <span class="whitespace-nowrap text-sm text-zinc-500 dark:text-zinc-400">
                {{ $users->total() }} user{{ $users->total() === 1 ? '' : 's' }}
            </span>
        </div>

        <button
            type="button"
            wire:click="$toggle('showCreateForm')"
            class="rounded-lg bg-blue-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
        >
            {{ $showCreateForm ? 'Cancel' : 'New user' }}
        </button>
    </div>

    @if ($showCreateForm)
        <div class="grid gap-3 rounded-xl border border-zinc-200 p-4 sm:grid-cols-2 lg:grid-cols-5 dark:border-zinc-700">
            <div class="flex flex-col gap-1">
                <input type="text" wire:model="newName" placeholder="Name" class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" />
                @error('newName') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
            </div>
            <div class="flex flex-col gap-1">
                <input type="text" wire:model="newUsername" placeholder="Username" class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" />
                @error('newUsername') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
            </div>
            <div class="flex flex-col gap-1">
                <input type="email" wire:model="newEmail" placeholder="Email" class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" />
                @error('newEmail') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
            </div>
            <div class="flex flex-col gap-1">
                <input type="password" wire:model="newPassword" placeholder="Password" class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" />
                @error('newPassword') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
            </div>
            <div class="flex flex-col gap-1">
                <input type="password" wire:model="newPasswordConfirmation" placeholder="Confirm password" class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" />
                @error('newPasswordConfirmation') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
            </div>
            <div class="sm:col-span-2 lg:col-span-5">
                <button type="button" wire:click="createUser" class="rounded-lg bg-blue-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-blue-700">
                    Create
                </button>
            </div>
        </div>
    @endif

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3">User</th>
                    <th class="px-4 py-3">Email</th>
                    <th class="px-4 py-3">Posts</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Joined</th>
                    <th class="px-4 py-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                @forelse ($users as $user)
                    @if ($editingUserId === $user->id)
                        <tr class="bg-zinc-50 dark:bg-zinc-900">
                            <td class="px-4 py-3" colspan="6">
                                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                    <div class="flex flex-col gap-1">
                                        <input type="text" wire:model="editName" placeholder="Name" class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" />
                                        @error('editName') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <input type="text" wire:model="editUsername" placeholder="Username" class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" />
                                        @error('editUsername') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <input type="email" wire:model="editEmail" placeholder="Email" class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" />
                                        @error('editEmail') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <input type="text" wire:model="editBio" placeholder="Bio" class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" />
                                        @error('editBio') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                                    </div>
                                </div>
                                <div class="mt-2 flex gap-2">
                                    <button type="button" wire:click="saveEdit" class="rounded-lg bg-blue-600 px-3 py-1 text-xs font-medium text-white hover:bg-blue-700">Save</button>
                                    <button type="button" wire:click="cancelEdit" class="rounded-lg border border-zinc-300 px-3 py-1 text-xs text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">Cancel</button>
                                </div>
                            </td>
                        </tr>
                    @else
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-900">
                            <td class="px-4 py-3">
                                <div class="font-medium text-zinc-800 dark:text-zinc-100">{{ $user->username ?? '(no username)' }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $user->name }}</div>
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $user->email }}</td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $user->posts_count }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @if ($user->trashed())
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700 dark:bg-red-900/40 dark:text-red-300">Deleted</span>
                                    @elseif (! $user->is_active)
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">Deactivated</span>
                                    @else
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">Active</span>
                                    @endif
                                    @if ($user->is_admin)
                                        <span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">Admin</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-zinc-500 dark:text-zinc-400">{{ $user->created_at->diffForHumans() }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-2 text-xs">
                                    @if ($user->trashed())
                                        <button type="button" wire:click="restoreUser({{ $user->id }})" wire:confirm="Restore this account?" class="text-blue-600 hover:underline dark:text-blue-400">Restore</button>
                                    @else
                                        <button type="button" wire:click="startEdit({{ $user->id }})" class="text-blue-600 hover:underline dark:text-blue-400">Edit</button>
                                        <button type="button" wire:click="toggleActive({{ $user->id }})" class="text-amber-600 hover:underline dark:text-amber-400">
                                            {{ $user->is_active ? 'Deactivate' : 'Reactivate' }}
                                        </button>
                                        <button type="button" wire:click="toggleAdmin({{ $user->id }})" class="text-zinc-600 hover:underline dark:text-zinc-300">
                                            {{ $user->is_admin ? 'Remove admin' : 'Make admin' }}
                                        </button>
                                        <button type="button" wire:click="deleteUser({{ $user->id }})" wire:confirm="Delete this account? It can be restored within the retention window." class="text-red-600 hover:underline dark:text-red-400">Delete</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-zinc-500 dark:text-zinc-400" colspan="6">No users found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $users->links() }}
</div>
