<?php

namespace App\Livewire;

use App\Enums\OcrLabelVerdict;
use App\Models\OcrLabel;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\AdminAuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One extraction at a time: the screenshot, the text the engine produced, and a verdict.
 *
 * This screen shows OCR text unredacted, unlike OcrMediaTable — you cannot judge an
 * extraction without reading it. The exchange is that labelling is itself the record: every
 * verdict is a durable row naming who read it, and the first view of each item is
 * audit-logged. Gated on `manageModeration`, so a read-only auditor cannot use it to browse
 * text the other screen redacts.
 */
class OcrLabelReview extends Component
{
    /** Narrows the queue — 'empty' is where a missing language pack shows up as false success. */
    #[Url]
    public string $filter = '';

    public ?int $mediaId = null;

    public string $notes = '';

    public ?string $flashMessage = null;

    /** Ids skipped this session, so "Skip" advances instead of handing back the same item. */
    /** @var list<int> */
    public array $skipped = [];

    public function mount(): void
    {
        Gate::authorize('manageModeration');
        $this->loadNext();
    }

    public function updatedFilter(): void
    {
        $this->skipped = [];
        $this->loadNext();
    }

    public function skip(): void
    {
        if ($this->mediaId !== null) {
            $this->skipped[] = $this->mediaId;
        }

        $this->loadNext();
    }

    public function label(string $verdict, AdminAuditLogger $audit): void
    {
        Gate::authorize('manageModeration');

        $decision = OcrLabelVerdict::tryFrom($verdict);
        $media = $this->mediaId === null ? null : PostMedia::find($this->mediaId);

        if ($decision === null || $media === null) {
            $this->flashMessage = 'That item is no longer available.';
            $this->loadNext();

            return;
        }

        // updateOrCreate keyed on the extraction, not the media: re-labelling the same output
        // corrects a verdict, while a re-run under a new engine is a new thing to judge.
        OcrLabel::updateOrCreate(
            [
                'post_media_id' => $media->id,
                'labeled_by' => $this->admin()->id,
                'ocr_text_hash' => OcrLabel::hashFor($media->ocr_text),
            ],
            [
                'verdict' => $decision,
                'notes' => trim($this->notes) === '' ? null : trim($this->notes),
                'ocr_char_count' => $media->ocr_text === null ? 0 : mb_strlen($media->ocr_text),
                'ocr_source' => $media->ocr_source,
                'engine_version' => $media->ocr_version,
                'ocr_language' => $media->ocr_language,
            ],
        );

        $audit->record($this->admin(), 'ocr.labelled', $media, $decision->value);
        $this->flashMessage = 'Recorded: '.$decision->label().'.';
        $this->notes = '';
        $this->loadNext();
    }

    public function render(): View
    {
        $media = $this->mediaId === null ? null : PostMedia::with('post.user')->find($this->mediaId);

        return view('livewire.ocr-label-review', [
            'media' => $media,
            'verdicts' => OcrLabelVerdict::cases(),
            'remaining' => $this->queue()->count(),
            'labelledByMe' => OcrLabel::query()->where('labeled_by', $this->admin()->id)->count(),
        ]);
    }

    /**
     * Unlabelled-by-me extractions that actually ran. `skipped` rows never ran an engine, so
     * there is nothing to judge; `failed` rows produced no output to compare.
     *
     * @return Builder<PostMedia>
     */
    private function queue(): Builder
    {
        return PostMedia::query()
            ->where('ocr_status', PostMedia::PROCESSING_READY)
            ->whereNotNull('ocr_version')
            ->when($this->filter === 'empty', fn ($query) => $query->whereNull('ocr_text'))
            ->when($this->filter === 'text', fn ($query) => $query->whereNotNull('ocr_text'))
            ->when($this->filter === 'device', fn ($query) => $query->where('ocr_source', PostMedia::OCR_SOURCE_DEVICE))
            ->when($this->filter === 'server', fn ($query) => $query->where('ocr_source', PostMedia::OCR_SOURCE_SERVER))
            ->whereNotIn('id', $this->skipped)
            // Only my own labels exclude an item, so two reviewers can independently label the
            // same extraction and their disagreement stays visible.
            ->whereDoesntHave('ocrLabels', fn ($labels) => $labels->where('labeled_by', $this->admin()->id));
    }

    private function loadNext(): void
    {
        // Oldest first: a stable order means a second reviewer walks the same sequence, so
        // overlapping coverage accumulates instead of scattering across the whole corpus.
        $this->mediaId = $this->queue()->orderBy('id')->value('id');
        $this->notes = '';
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
