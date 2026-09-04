<?php

namespace App\Livewire;

use App\Models\OcrVerification;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\AdminAuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Per-image OCR results for debugging a specific screenshot.
 *
 * Text is redacted by default and revealed one row at a time, behind `manageModeration` and a
 * written reason that is audit-logged — the same contract as every other moderation action.
 * The reasoning is in the OCR section of CLAUDE.md: this text is the user's private screenshot
 * content, and the safety analyzer exists precisely because it contains credentials and IDs.
 * A screen that renders it in bulk turns the dashboard into a searchable index of that.
 */
class OcrMediaTable extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $source = '';

    #[Url]
    public string $safety = '';

    #[Url]
    public string $text = '';

    /** Media id whose text is currently revealed — one at a time, never a whole page. */
    public ?int $revealedId = null;

    public ?int $revealingId = null;

    public string $revealReason = '';

    public ?string $flashMessage = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'source', 'safety', 'text'], true)) {
            $this->resetPage();
            $this->revealedId = null;
        }
    }

    public function startReveal(int $mediaId): void
    {
        Gate::authorize('manageModeration');
        $this->revealingId = $mediaId;
        $this->revealReason = '';
    }

    public function cancelReveal(): void
    {
        $this->revealingId = null;
        $this->revealReason = '';
    }

    public function reveal(AdminAuditLogger $audit): void
    {
        Gate::authorize('manageModeration');

        if ($this->revealingId === null) {
            return;
        }

        $this->validate(
            ['revealReason' => ['required', 'string', 'min:3', 'max:500']],
            ['revealReason.required' => 'A reason is required to read a user\'s screenshot text.'],
        );

        $media = PostMedia::findOrFail($this->revealingId);
        // Logged against the media, not the text: the audit trail records that someone read
        // it and why, and never copies the content it was protecting.
        $audit->record($this->admin(), 'ocr.text_revealed', $media, $this->revealReason);

        $this->revealedId = $media->id;
        $this->revealingId = null;
        $this->revealReason = '';
        $this->flashMessage = 'Text revealed and recorded in the audit log.';
    }

    public function hide(): void
    {
        $this->revealedId = null;
    }

    public function render(): View
    {
        $media = PostMedia::query()
            ->with(['post.user'])
            ->when($this->status !== '', fn ($query) => $query->where('ocr_status', $this->status))
            ->when($this->source === 'unknown', fn ($query) => $query->whereNull('ocr_source'))
            ->when($this->source !== '' && $this->source !== 'unknown', fn ($query) => $query->where('ocr_source', $this->source))
            ->when($this->safety !== '', fn ($query) => $query->where('safety_status', $this->safety))
            ->when($this->text === 'empty', fn ($query) => $query->whereNull('ocr_text'))
            ->when($this->text === 'present', fn ($query) => $query->whereNotNull('ocr_text'))
            ->when($this->search !== '', function ($query): void {
                // Deliberately never searches ocr_text. It is encrypted at rest, so a LIKE
                // could not match anyway — and building a text index over it is exactly the
                // searchable archive of private content this screen avoids being.
                $query->where('id', ctype_digit($this->search) ? (int) $this->search : -1)
                    ->orWhere('post_id', ctype_digit($this->search) ? (int) $this->search : -1)
                    ->orWhereHas('post.user', fn ($user) => $user->where('username', 'like', '%'.$this->search.'%'));
            })
            ->latest('id')
            ->paginate(20);

        return view('livewire.ocr-media-table', [
            'media' => $media,
            'verifications' => OcrVerification::query()
                ->whereIn('post_media_id', $media->getCollection()->pluck('id'))
                ->get()
                ->keyBy('post_media_id'),
        ]);
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
