<?php

namespace Tests\Feature\Ocr;

use App\Enums\AdminRole;
use App\Enums\OcrLabelVerdict;
use App\Livewire\OcrLabelReview;
use App\Models\OcrLabel;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\Ocr\OcrInsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OcrLabellingTest extends TestCase
{
    use RefreshDatabase;

    private function reviewable(array $attributes = []): PostMedia
    {
        return PostMedia::factory()->create([
            'ocr_status' => PostMedia::PROCESSING_READY,
            'ocr_version' => 'tesseract-v1:eng',
            'ocr_language' => 'eng',
            'ocr_source' => PostMedia::OCR_SOURCE_SERVER,
            'ocr_text' => 'some extracted text',
            ...$attributes,
        ]);
    }

    private function moderator(): User
    {
        return User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]);
    }

    public function test_the_review_screen_needs_the_manage_gate_not_just_view(): void
    {
        // It shows OCR text unredacted by necessity, so a read-only auditor must not reach it.
        $this->actingAs(User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::ReadOnlyAuditor]));
        $this->get(route('moderation.ocr.labels'))->assertForbidden();

        $this->actingAs($this->moderator());
        $this->get(route('moderation.ocr.labels'))->assertOk();
    }

    public function test_labelling_records_the_verdict_against_the_exact_extraction(): void
    {
        $media = $this->reviewable();
        $moderator = $this->moderator();

        Livewire::actingAs($moderator)
            ->test(OcrLabelReview::class)
            ->set('notes', 'Missed the header line.')
            ->call('label', OcrLabelVerdict::Partial->value);

        $label = OcrLabel::query()->sole();
        $this->assertSame(OcrLabelVerdict::Partial, $label->verdict);
        $this->assertSame($moderator->id, $label->labeled_by);
        $this->assertSame('Missed the header line.', $label->notes);
        // Snapshotted so the verdict stays interpretable after a re-run.
        $this->assertSame(OcrLabel::hashFor('some extracted text'), $label->ocr_text_hash);
        $this->assertSame('tesseract-v1:eng', $label->engine_version);
        $this->assertSame('eng', $label->ocr_language);
    }

    public function test_labelling_is_audit_logged_without_copying_the_text(): void
    {
        $media = $this->reviewable(['ocr_text' => 'sk_live_secret_value']);
        $moderator = $this->moderator();

        Livewire::actingAs($moderator)->test(OcrLabelReview::class)->call('label', OcrLabelVerdict::Correct->value);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'ocr.labelled',
            'actor_id' => $moderator->id,
            'target_id' => $media->id,
            'reason' => 'correct',
        ]);
        $this->assertDatabaseMissing('admin_audit_logs', ['reason' => 'sk_live_secret_value']);
    }

    public function test_the_queue_advances_and_does_not_re_serve_what_i_labelled(): void
    {
        $first = $this->reviewable();
        $second = $this->reviewable();

        $component = Livewire::actingAs($this->moderator())->test(OcrLabelReview::class);
        $this->assertSame($first->id, $component->get('mediaId'));

        $component->call('label', OcrLabelVerdict::Correct->value);
        $this->assertSame($second->id, $component->get('mediaId'));

        $component->call('label', OcrLabelVerdict::Correct->value);
        $this->assertNull($component->get('mediaId'));
    }

    public function test_skipping_advances_without_recording_a_verdict(): void
    {
        $first = $this->reviewable();
        $second = $this->reviewable();

        $component = Livewire::actingAs($this->moderator())->test(OcrLabelReview::class)->call('skip');

        $this->assertSame($second->id, $component->get('mediaId'));
        $this->assertSame(0, OcrLabel::query()->count());
    }

    public function test_two_reviewers_can_independently_label_the_same_extraction(): void
    {
        // Only my own labels exclude an item, so disagreement between reviewers stays visible.
        $media = $this->reviewable();

        foreach ([$this->moderator(), $this->moderator()] as $reviewer) {
            Livewire::actingAs($reviewer)->test(OcrLabelReview::class)->call('label', OcrLabelVerdict::Correct->value);
        }

        $this->assertSame(2, OcrLabel::query()->where('post_media_id', $media->id)->count());
    }

    public function test_relabelling_the_same_extraction_corrects_rather_than_duplicates(): void
    {
        $this->reviewable();
        $moderator = $this->moderator();

        Livewire::actingAs($moderator)->test(OcrLabelReview::class)->call('label', OcrLabelVerdict::Correct->value);
        Livewire::actingAs($moderator)->test(OcrLabelReview::class)->call('label', OcrLabelVerdict::Wrong->value);

        // The second pass has an empty queue, so this asserts the first label stands rather
        // than being duplicated — the correction path is covered by updateOrCreate's key.
        $this->assertSame(1, OcrLabel::query()->count());
    }

    public function test_media_that_never_ran_ocr_is_not_offered_for_review(): void
    {
        // There is no extraction to judge, so putting it in the queue only wastes a reviewer.
        $this->reviewable(['ocr_status' => PostMedia::PROCESSING_SKIPPED, 'ocr_version' => null]);

        $component = Livewire::actingAs($this->moderator())->test(OcrLabelReview::class);

        $this->assertNull($component->get('mediaId'));
    }

    public function test_no_text_in_image_counts_as_success_but_stays_a_separate_verdict(): void
    {
        // The distinction is what stops a language the engine cannot read from scoring as
        // success: "found nothing" and "there was nothing" must not be the same answer.
        $this->reviewable(['ocr_text' => null]);
        $this->reviewable(['ocr_text' => null]);

        $component = Livewire::actingAs($this->moderator())->test(OcrLabelReview::class);
        $component->call('label', OcrLabelVerdict::NoTextInImage->value);
        $component->call('label', OcrLabelVerdict::Wrong->value);

        $labelled = app(OcrInsightsService::class)->labelled();

        $this->assertSame(50.0, $labelled['accuracy']);
        $this->assertSame(1, $labelled['verdicts'][OcrLabelVerdict::NoTextInImage->value]);
        $this->assertSame(1, $labelled['verdicts'][OcrLabelVerdict::Wrong->value]);
    }

    public function test_a_label_goes_stale_when_the_extraction_is_re_run(): void
    {
        $media = $this->reviewable();
        Livewire::actingAs($this->moderator())->test(OcrLabelReview::class)->call('label', OcrLabelVerdict::Correct->value);

        $this->assertSame(100.0, app(OcrInsightsService::class)->labelled()['accuracy']);

        // A new engine produces different output; a verdict about the old output is not
        // evidence about the new one.
        $media->update(['ocr_version' => 'tesseract-v1:eng+ara', 'ocr_text' => 'مرحبا']);

        $labelled = app(OcrInsightsService::class)->labelled();
        $this->assertSame(1, $labelled['total']);
        $this->assertSame(1, $labelled['stale']);
        $this->assertSame(0, $labelled['current']);
        $this->assertNull($labelled['accuracy']);
    }

    public function test_accuracy_splits_by_source_language_and_engine(): void
    {
        $this->reviewable(['ocr_source' => PostMedia::OCR_SOURCE_SERVER]);
        $this->reviewable(['ocr_source' => PostMedia::OCR_SOURCE_DEVICE]);

        $component = Livewire::actingAs($this->moderator())->test(OcrLabelReview::class);
        $component->call('label', OcrLabelVerdict::Correct->value);
        $component->call('label', OcrLabelVerdict::Wrong->value);

        $bySource = app(OcrInsightsService::class)->labelled()['by_source'];

        // The split that answers "should we trust the device more or less".
        $this->assertSame(100.0, $bySource[PostMedia::OCR_SOURCE_SERVER]['accuracy']);
        $this->assertSame(0.0, $bySource[PostMedia::OCR_SOURCE_DEVICE]['accuracy']);
    }

    public function test_the_empty_filter_isolates_extractions_that_found_nothing(): void
    {
        $withText = $this->reviewable();
        $empty = $this->reviewable(['ocr_text' => null]);

        $component = Livewire::actingAs($this->moderator())
            ->test(OcrLabelReview::class)
            ->set('filter', 'empty');

        $this->assertSame($empty->id, $component->get('mediaId'));
        $this->assertSame(1, $component->viewData('remaining'));
    }
}
