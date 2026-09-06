<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * One-off repair for accounts created before `CompleteSocialLogin` stopped mass-assigning
 * `email_verified_at` through a non-fillable field. The timestamp was silently dropped, so every
 * social sign-up landed unverified and was then refused by `EnsureApiEmailIsVerified` on the next
 * call — sign-in succeeded, the rest of the API did not.
 *
 * Marking an address verified is an identity claim, so this only touches accounts where the
 * provider demonstrably vouched for the address at the time:
 *
 * - **Facebook** — `FacebookTokenVerifier` hardcodes `emailVerified: true`, because Meta only
 *   returns addresses it has already confirmed. Every Facebook account qualifies.
 * - **Google on a Google-operated domain** — `GoogleTokenVerifier` reads `email_verified` off the
 *   token, and that *can* be false (an unverified Workspace domain), so the provider alone is not
 *   proof. A gmail.com / googlemail.com address is a Google-run mailbox, which is.
 *
 * A Google account on a custom domain is deliberately left alone: we do not store what the token
 * claimed at signup, and guessing in favour of verification is the wrong direction to guess.
 * Those users verify by email like anyone else.
 */
class BackfillSocialEmailVerification extends Command
{
    /** @var string */
    protected $signature = 'users:backfill-social-verification {--dry-run : List the accounts that would be marked verified, and change nothing}';

    /** @var string */
    protected $description = 'Marks social accounts email-verified where the provider had already confirmed the address.';

    /** Mailboxes Google itself operates, so `email_verified` was necessarily true. */
    private const GOOGLE_OPERATED_DOMAINS = ['gmail.com', 'googlemail.com'];

    public function handle(): int
    {
        $users = $this->eligible()->get();

        if ($users->isEmpty()) {
            $this->info('Nothing to backfill — every eligible social account is already verified.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'email', 'providers', 'created'],
            $users->map(fn (User $user): array => [
                $user->id,
                $user->email,
                $user->socialAccounts->pluck('provider')->unique()->sort()->implode(', '),
                $user->created_at?->toDateString() ?? '—',
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->comment($users->count().' account(s) would be marked verified. No changes made.');

            return self::SUCCESS;
        }

        $verifiedAt = now();

        // Re-filtered rather than keyed off the ids read above, so a signup that verifies itself
        // between the read and the write is not overwritten with a later timestamp.
        $updated = $this->eligible()->update(['email_verified_at' => $verifiedAt]);

        Log::info('Backfilled email verification for social accounts.', [
            'count' => $updated,
            'user_ids' => $users->pluck('id')->all(),
            'verified_at' => $verifiedAt->toIso8601String(),
        ]);

        $this->info("Marked {$updated} social account(s) email-verified.");

        return self::SUCCESS;
    }

    /**
     * The provider test belongs on the social_accounts subquery; the domain test belongs on the
     * users row, because the address being verified is the one on the account rather than
     * whatever the provider record happens to hold.
     *
     * @return Builder<User>
     */
    private function eligible(): Builder
    {
        return User::query()
            ->with('socialAccounts')
            ->whereNull('email_verified_at')
            ->where(function (Builder $eligible): void {
                $eligible
                    ->whereHas('socialAccounts', function (Builder $accounts): void {
                        $accounts->where('provider', 'facebook');
                    })
                    ->orWhere(function (Builder $google): void {
                        $google
                            ->whereHas('socialAccounts', function (Builder $accounts): void {
                                $accounts->where('provider', 'google');
                            })
                            ->where(function (Builder $domain): void {
                                foreach (self::GOOGLE_OPERATED_DOMAINS as $operated) {
                                    $domain->orWhere('users.email', 'like', '%@'.$operated);
                                }
                            });
                    });
            });
    }
}
