# Points rewards

The Registration & invites dashboard owns the amount and enabled state for each supported reward.
Settings are stored as `FeatureFlag` rows named `points.reward.<action>` and cached by
`PointRewardService`; successful admin writes invalidate the corresponding cache after commit.
The existing `registration.invite_only` points payload remains a fallback for invitation rewards
until their individual reward flags have been configured.

Current action keys and grant rules:

| Action | Grant rule | Default |
| --- | --- | --- |
| `invitee_registration` | Once after the invited member verifies email | 50, enabled |
| `inviter_referral` | Once after the existing maturity window while the invitee is active | 50, enabled |
| `first_post` | Once after the member creates their first post | 30, enabled |
| `first_private_save` | Once after the member creates their first private save | 30, enabled |
| `account_registration` | Once after email verification | 0, disabled |
| `daily_login` | At most once per user per calendar day | 0, disabled |

Every grant goes through `PointRewardService::awardOnce()` and the unique ledger idempotency key.
To add another reward, add its action and safe default to `PointRewardService::catalog()`, call
`awardOnce()` from the authoritative backend action (after that action has succeeded), and add a
focused test. The dashboard then exposes its amount and enabled switch automatically. Product
actions that could be gamed, such as moderation reports, need an explicit eligibility rule before
they should be wired as rewards.
