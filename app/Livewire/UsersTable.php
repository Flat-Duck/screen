<?php

namespace App\Livewire;

use App\Actions\Accounts\CreateUserByAdmin;
use App\Actions\Accounts\RestoreDeletedAccount;
use App\Actions\Accounts\SetUserActiveState;
use App\Actions\Accounts\SetUserAdminState;
use App\Data\Auth\RegisterUserData;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin user management: search/list, create, inline edit, activate/deactivate,
 * grant/revoke admin, soft-delete/restore. Every mutation reuses the same Actions the
 * mobile API and console commands already use — this page is a thin UI over them, not
 * a parallel implementation.
 *
 * Self-protection: none of the destructive/access-changing actions (deactivate,
 * remove-admin, delete) can target the signed-in admin's own row, to avoid an accidental
 * self-lockout with no other admin around to undo it.
 */
class UsersTable extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public bool $showDeleted = false;

    public bool $showCreateForm = false;

    public string $newName = '';

    public string $newUsername = '';

    public string $newEmail = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public ?int $editingUserId = null;

    public string $editName = '';

    public string $editUsername = '';

    public string $editEmail = '';

    public string $editBio = '';

    public ?string $flashMessage = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function createUser(CreateUserByAdmin $createUser): void
    {
        $this->validate([
            'newName' => ['required', 'string', 'max:255'],
            'newUsername' => ['required', 'string', 'min:3', 'max:30', 'alpha_dash', 'unique:users,username'],
            'newEmail' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'newPassword' => ['required', Password::default()],
            'newPasswordConfirmation' => ['required', 'same:newPassword'],
        ]);

        $user = $createUser(new RegisterUserData($this->newName, $this->newUsername, $this->newEmail, $this->newPassword));

        $this->reset(['newName', 'newUsername', 'newEmail', 'newPassword', 'newPasswordConfirmation', 'showCreateForm']);
        $this->flashMessage = "Created {$user->username}.";
    }

    public function startEdit(int $userId): void
    {
        $user = User::withTrashed()->findOrFail($userId);

        $this->editingUserId = $userId;
        $this->editName = $user->name;
        $this->editUsername = (string) $user->username;
        $this->editEmail = $user->email;
        $this->editBio = (string) $user->bio;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingUserId', 'editName', 'editUsername', 'editEmail', 'editBio']);
    }

    public function saveEdit(): void
    {
        $user = User::withTrashed()->findOrFail($this->editingUserId);

        $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editUsername' => ['required', 'string', 'min:3', 'max:30', 'alpha_dash', 'unique:users,username,'.$user->id],
            'editEmail' => ['required', 'string', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'editBio' => ['nullable', 'string', 'max:500'],
        ]);

        $user->fill([
            'name' => $this->editName,
            'username' => $this->editUsername,
            'email' => $this->editEmail,
            'bio' => $this->editBio !== '' ? $this->editBio : null,
        ])->save();

        $this->cancelEdit();
        $this->flashMessage = "Updated {$user->username}.";
    }

    public function toggleActive(int $userId, SetUserActiveState $setActiveState): void
    {
        $user = User::findOrFail($userId);

        if ($user->is($this->currentAdmin())) {
            $this->flashMessage = "You can't deactivate your own account.";

            return;
        }

        $activate = ! $user->is_active;
        $setActiveState($user, $activate);
        $this->flashMessage = $activate ? "Reactivated {$user->username}." : "Deactivated {$user->username}.";
    }

    public function toggleAdmin(int $userId, SetUserAdminState $setAdminState): void
    {
        $user = User::findOrFail($userId);

        if ($user->is($this->currentAdmin())) {
            $this->flashMessage = "You can't remove your own admin access.";

            return;
        }

        $grant = ! $user->is_admin;
        $setAdminState($user, $grant);
        $this->flashMessage = $grant ? "Granted admin access to {$user->username}." : "Revoked admin access from {$user->username}.";
    }

    public function deleteUser(int $userId, AccountService $accountService): void
    {
        $user = User::findOrFail($userId);

        if ($user->is($this->currentAdmin())) {
            $this->flashMessage = "You can't delete your own account from here.";

            return;
        }

        $accountService->deleteAccount($user);
        $this->flashMessage = "Deleted {$user->username}.";
    }

    public function restoreUser(int $userId, RestoreDeletedAccount $restoreAccount): void
    {
        $result = $restoreAccount($userId);
        $this->flashMessage = "Restored {$result->user->username} and {$result->restoredPosts} post(s).";
    }

    private function currentAdmin(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    public function render(): View
    {
        $users = User::query()
            ->when($this->showDeleted, fn ($query) => $query->onlyTrashed(), fn ($query) => $query)
            ->when($this->search !== '', function ($query) {
                $query->where(function ($query) {
                    $query->where('username', 'like', "%{$this->search}%")
                        ->orWhere('name', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%");
                });
            })
            ->withCount('posts')
            ->latest('id')
            ->paginate(15);

        return view('livewire.users-table', ['users' => $users]);
    }
}
