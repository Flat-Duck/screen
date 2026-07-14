<?php

namespace App\Livewire;

use App\Models\Comment;
use App\Models\Device;
use App\Models\DevicePushToken;
use App\Models\Post;
use App\Models\User;
use App\Notifications\NewFollowerNotification;
use App\Notifications\PostCommentedNotification;
use App\Notifications\PostLikedNotification;
use App\Services\Fcm\FcmClient;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Livewire\Component;

/**
 * Admin-only QA tool for exercising notification delivery end-to-end, in two
 * independent ways:
 *
 *  - "Send a push" talks to {@see FcmClient} directly with an arbitrary
 *    title/body/image — bypasses the Notification system entirely, so it works even
 *    for a recipient who's disabled that category in their notification settings
 *    (deliberately: this is for verifying FCM delivery itself, not settings gating).
 *  - "Send a notification" dispatches one of the app's *real*
 *    {@see NewFollowerNotification}/{@see PostLikedNotification}/
 *    {@see PostCommentedNotification} classes against existing users/posts — the same
 *    code path a real follow/like/comment triggers (database row + FcmChannel, subject
 *    to the recipient's own settings), so it's the actual thing to use for "does the
 *    in-app notification list and push both render correctly." Deliberately never
 *    creates a real Follow/Like/Comment row — the chosen post/actor are only ever
 *    passed into the notification, never persisted as new social-graph data.
 */
class NotificationTester extends Component
{
    // --- Ad-hoc push ---
    public string $pushTarget = 'user';

    public string $pushUserSearch = '';

    public ?int $pushUserId = null;

    public string $pushDeviceSearch = '';

    public ?int $pushDeviceId = null;

    public string $pushTitle = '';

    public string $pushBody = '';

    public string $pushImageUrl = '';

    public ?string $pushResult = null;

    // --- Real notification test ---
    public string $notifRecipientSearch = '';

    public ?int $notifRecipientId = null;

    public string $notifType = 'follow';

    public string $notifActorSearch = '';

    public ?int $notifActorId = null;

    public string $notifPostSearch = '';

    public ?int $notifPostId = null;

    public string $notifCommentBody = 'This is a test comment sent from the admin dashboard.';

    public ?string $notifResult = null;

    public function sendPush(): void
    {
        $this->pushResult = null;

        $this->validate([
            'pushTitle' => ['required', 'string', 'max:255'],
            'pushBody' => ['required', 'string', 'max:1000'],
            'pushImageUrl' => ['nullable', 'url'],
            'pushUserId' => ['required_if:pushTarget,user', 'nullable', 'integer'],
            'pushDeviceId' => ['required_if:pushTarget,device', 'nullable', 'integer'],
        ]);

        $fcm = app(FcmClient::class);

        if (! $fcm->isConfigured()) {
            $this->pushResult = 'FCM is not configured on this environment (FIREBASE_PROJECT_ID / FIREBASE_CREDENTIALS_PATH).';

            return;
        }

        $tokens = match ($this->pushTarget) {
            'user' => DevicePushToken::query()
                ->whereHas('device', fn ($query) => $query->where('user_id', $this->pushUserId))
                ->pluck('fcm_token', 'id'),
            'device' => DevicePushToken::query()->where('device_id', $this->pushDeviceId)->pluck('fcm_token', 'id'),
            'all' => DevicePushToken::query()->pluck('fcm_token', 'id'),
            default => collect(),
        };

        if ($tokens->isEmpty()) {
            $this->pushResult = 'No push tokens found for that target.';

            return;
        }

        [$sent, $invalid, $failed] = [0, 0, 0];

        foreach ($tokens as $tokenId => $fcmToken) {
            $result = $fcm->send(
                $fcmToken,
                $this->pushTitle,
                $this->pushBody,
                ['type' => 'admin_test'],
                $this->pushImageUrl !== '' ? $this->pushImageUrl : null,
            );

            match ($result) {
                'ok' => $sent++,
                'invalid_token' => $invalid++,
                default => $failed++,
            };
        }

        $this->pushResult = "Sent to {$sent} device(s)."
            .($invalid > 0 ? " {$invalid} had stale/invalid tokens (removed)." : '')
            .($failed > 0 ? " {$failed} failed transiently." : '');
    }

    public function sendTestNotification(): void
    {
        $this->notifResult = null;

        $this->validate([
            'notifRecipientId' => ['required', 'integer', 'exists:users,id'],
            'notifType' => ['required', 'in:follow,like,comment'],
            'notifActorId' => ['required', 'integer', 'exists:users,id', 'different:notifRecipientId'],
            'notifPostId' => ['required_if:notifType,like,comment', 'nullable', 'integer', 'exists:posts,id'],
            'notifCommentBody' => ['required_if:notifType,comment', 'nullable', 'string', 'max:500'],
        ]);

        $recipient = User::query()->findOrFail($this->notifRecipientId);
        $actor = User::query()->findOrFail($this->notifActorId);

        $notification = match ($this->notifType) {
            'follow' => new NewFollowerNotification($actor),
            'like' => new PostLikedNotification(Post::query()->findOrFail($this->notifPostId), $actor),
            'comment' => new PostCommentedNotification(
                Post::query()->findOrFail($this->notifPostId),
                new Comment(['body' => $this->notifCommentBody, 'post_id' => $this->notifPostId, 'user_id' => $actor->id]),
                $actor,
            ),
            default => throw new InvalidArgumentException("Unknown notification type: {$this->notifType}"),
        };

        $recipient->notify($notification);

        $this->notifResult = "Sent a {$this->notifType} notification to {$recipient->username} (from {$actor->username}) — check their in-app notification list.";
    }

    /** @return Collection<int, User> */
    private function searchUsers(string $term): Collection
    {
        return User::query()
            ->when($term !== '', fn ($query) => $query->where(function ($query) use ($term) {
                $query->where('username', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%");
            }))
            ->orderBy('username')
            ->limit(25)
            ->get();
    }

    /** @return Collection<int, Device> */
    private function searchDevices(string $term): Collection
    {
        return Device::query()
            ->with('user')
            ->when($term !== '', fn ($query) => $query->where(function ($query) use ($term) {
                $query->where('device_uuid', 'like', "%{$term}%")
                    ->orWhere('model', 'like', "%{$term}%")
                    ->orWhereHas('user', fn ($query) => $query->where('username', 'like', "%{$term}%"));
            }))
            ->whereHas('pushToken')
            ->latest('last_seen_at')
            ->limit(25)
            ->get();
    }

    /** @return Collection<int, Post> */
    private function searchPosts(string $term): Collection
    {
        return Post::query()
            ->with('user')
            ->when($term !== '', fn ($query) => $query->where(function ($query) use ($term) {
                $query->where('caption', 'like', "%{$term}%")
                    ->orWhereHas('user', fn ($query) => $query->where('username', 'like', "%{$term}%"));
            }))
            ->latest('id')
            ->limit(25)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.notification-tester', [
            'pushUserOptions' => $this->searchUsers($this->pushUserSearch),
            'pushDeviceOptions' => $this->searchDevices($this->pushDeviceSearch),
            'notifRecipientOptions' => $this->searchUsers($this->notifRecipientSearch),
            'notifActorOptions' => $this->searchUsers($this->notifActorSearch),
            'notifPostOptions' => $this->searchPosts($this->notifPostSearch),
        ]);
    }
}
