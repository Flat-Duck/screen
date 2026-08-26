<?php

namespace Tests\Unit\Services\Screenshots;

use App\Models\User;
use App\Models\UserOcrTrust;
use App\Services\Screenshots\OcrTrustSampler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OcrTrustSamplerTest extends TestCase
{
    use RefreshDatabase;

    private OcrTrustSampler $sampler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sampler = new OcrTrustSampler;
    }

    public function test_a_brand_new_account_is_always_sampled_regardless_of_the_claimed_text(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($this->sampler->shouldSample($user, 'some perfectly ordinary text'));
    }

    public function test_a_probation_account_is_always_sampled(): void
    {
        $user = User::factory()->create();
        UserOcrTrust::create(['user_id' => $user->id, 'trust_tier' => UserOcrTrust::TIER_PROBATION]);

        $this->assertTrue($this->sampler->shouldSample($user, 'some perfectly ordinary text'));
    }

    public function test_a_trusted_account_is_always_sampled_when_the_claimed_text_is_blank(): void
    {
        $user = User::factory()->create();
        UserOcrTrust::create(['user_id' => $user->id, 'trust_tier' => UserOcrTrust::TIER_TRUSTED]);

        $this->assertTrue($this->sampler->shouldSample($user, null));
        $this->assertTrue($this->sampler->shouldSample($user, '   '));
    }

    public function test_recording_matches_promotes_a_new_account_to_trusted_at_the_threshold(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 19; $i++) {
            $this->sampler->recordMatch($user);
        }
        $this->assertSame(UserOcrTrust::TIER_NEW, UserOcrTrust::where('user_id', $user->id)->value('trust_tier'));

        $this->sampler->recordMatch($user);
        $this->assertSame(UserOcrTrust::TIER_TRUSTED, UserOcrTrust::where('user_id', $user->id)->value('trust_tier'));
    }

    public function test_recording_a_mismatch_resets_a_trusted_account_to_probation(): void
    {
        $user = User::factory()->create();
        UserOcrTrust::create(['user_id' => $user->id, 'trust_tier' => UserOcrTrust::TIER_TRUSTED, 'consecutive_verified_count' => 40]);

        $this->sampler->recordMismatch($user);

        $trust = UserOcrTrust::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(UserOcrTrust::TIER_PROBATION, $trust->trust_tier);
        $this->assertSame(0, $trust->consecutive_verified_count);
        $this->assertNotNull($trust->last_mismatch_at);
    }
}
