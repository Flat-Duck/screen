<?php

namespace Tests\Feature\Ocr;

use App\Enums\AdminRole;
use App\Livewire\OcrMediaTable;
use App\Models\OcrVerification;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\Ocr\OcrInsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OcrDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_requires_view_moderation(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('moderation.ocr.index'))->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::ReadOnlyAuditor]));
        $this->get(route('moderation.ocr.index'))->assertOk();
    }

    public function test_ocr_text_is_redacted_until_it_is_deliberately_revealed(): void
    {
        $media = PostMedia::factory()->create(['ocr_text' => 'sk_live_super_secret_key', 'ocr_status' => PostMedia::PROCESSING_READY]);

        Livewire::actingAs(User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]))
            ->test(OcrMediaTable::class)
            ->assertDontSee('sk_live_super_secret_key')
            ->assertSee('redacted')
            ->call('startReveal', $media->id)
            ->set('revealReason', 'Investigating report #12 on this screenshot.')
            ->call('reveal')
            ->assertSee('sk_live_super_secret_key');
    }

    public function test_revealing_requires_a_reason_and_is_audit_logged(): void
    {
        $media = PostMedia::factory()->create(['ocr_text' => 'private content', 'ocr_status' => PostMedia::PROCESSING_READY]);
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]);

        $component = Livewire::actingAs($admin)
            ->test(OcrMediaTable::class)
            ->call('startReveal', $media->id)
            ->call('reveal')
            ->assertHasErrors('revealReason');

        $this->assertDatabaseCount('admin_audit_logs', 0);

        $component->set('revealReason', 'Checking a sensitive-content report.')->call('reveal');

        // Records that someone read it and why — and never copies the content it protects.
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'ocr.text_revealed',
            'actor_id' => $admin->id,
            'target_id' => $media->id,
            'reason' => 'Checking a sensitive-content report.',
        ]);
        $this->assertDatabaseMissing('admin_audit_logs', ['reason' => 'private content']);
    }

    public function test_an_auditor_can_browse_but_cannot_reveal(): void
    {
        $media = PostMedia::factory()->create(['ocr_text' => 'private content']);

        Livewire::actingAs(User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::ReadOnlyAuditor]))
            ->test(OcrMediaTable::class)
            ->call('startReveal', $media->id)
            ->assertForbidden();
    }

    public function test_never_ran_rows_are_excluded_from_the_outcome_rates(): void
    {
        // Seeded rows written straight to `ready` with no version never ran OCR. Counting
        // them as successes or as empty results is equally wrong.
        PostMedia::factory()->count(8)->create(['ocr_status' => PostMedia::PROCESSING_SKIPPED, 'ocr_version' => null]);
        PostMedia::factory()->count(3)->create(['ocr_status' => PostMedia::PROCESSING_READY, 'ocr_version' => 'v1', 'ocr_text' => 'text']);
        PostMedia::factory()->create(['ocr_status' => PostMedia::PROCESSING_FAILED, 'ocr_version' => 'v1']);

        $pipeline = app(OcrInsightsService::class)->pipeline();

        $this->assertSame(12, $pipeline['total']);
        $this->assertSame(8, $pipeline['never_ran']);
        $this->assertSame(4, $pipeline['ran']);
        $this->assertSame(25.0, $pipeline['failure_rate']);
        $this->assertSame(0.0, $pipeline['empty_text_rate']);
    }

    public function test_agreement_and_similarity_are_reported_separately(): void
    {
        // The whole point of recording both: the category verdict can call two genuinely
        // different readings a match, so a high agreement rate over a low similarity is the
        // signal that the trust loop's test is too coarse.
        OcrVerification::create(['ocr_source' => 'device', 'verdict' => OcrVerification::VERDICT_MATCH, 'similarity' => 1.0]);
        OcrVerification::create(['ocr_source' => 'device', 'verdict' => OcrVerification::VERDICT_MATCH, 'similarity' => 0.2]);
        OcrVerification::create(['ocr_source' => 'device', 'verdict' => OcrVerification::VERDICT_MISMATCH, 'similarity' => 0.0]);
        OcrVerification::create(['ocr_source' => 'device', 'verdict' => OcrVerification::VERDICT_UNVERIFIED]);

        $accuracy = app(OcrInsightsService::class)->accuracy();

        $this->assertSame(3, $accuracy['compared']);
        $this->assertSame(66.67, $accuracy['agreement_rate']);
        $this->assertSame(40.0, $accuracy['mean_similarity']);
        // Unverified rows must never count toward accuracy — they were never compared.
        $this->assertSame(1, $accuracy['unverified']);
        $this->assertSame(25.0, $accuracy['unverified_share']);
    }

    public function test_the_weekly_curve_buckets_only_compared_rows(): void
    {
        OcrVerification::create(['ocr_source' => 'device', 'verdict' => OcrVerification::VERDICT_MATCH, 'similarity' => 0.9]);
        OcrVerification::create(['ocr_source' => 'device', 'verdict' => OcrVerification::VERDICT_MISMATCH, 'similarity' => 0.1]);
        OcrVerification::create(['ocr_source' => 'device', 'verdict' => OcrVerification::VERDICT_UNVERIFIED]);

        $curve = app(OcrInsightsService::class)->curve();

        $this->assertCount(1, $curve);
        $this->assertSame(2, $curve[0]['compared']);
        $this->assertSame(50.0, $curve[0]['agreement']);
    }
}
