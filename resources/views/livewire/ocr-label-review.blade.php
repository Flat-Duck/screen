<div class="flex flex-col gap-4">
    @if($flashMessage)
        <div class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200">{{ $flashMessage }}</div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <select wire:model.live="filter" class="rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900">
            <option value="">Everything that ran OCR</option>
            <option value="empty">Found no text (where a missing language pack hides)</option>
            <option value="text">Found text</option>
            <option value="device">Device-sourced</option>
            <option value="server">Server-sourced</option>
        </select>
        <div class="text-sm text-zinc-500">{{ $remaining }} left in this queue · you have labelled {{ $labelledByMe }}</div>
    </div>

    @if($media === null)
        <div class="rounded-xl border border-zinc-200 p-8 text-center dark:border-zinc-700">
            <p class="text-sm text-zinc-500">Nothing left to review in this queue.</p>
        </div>
    @else
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="mb-2 flex items-center justify-between text-xs text-zinc-500">
                    <span>Media #{{ $media->id }} · post {{ $media->post_id }} · {{ $media->post?->user?->username ?? 'unknown author' }}</span>
                    <span>{{ $media->ocr_source ?? 'unknown source' }} · {{ $media->ocr_language ?? 'no language' }}</span>
                </div>
                <img src="{{ route('moderation.media.show', $media) }}" alt="Screenshot under OCR review" class="max-h-[32rem] w-full rounded-lg object-contain" />
            </div>

            <div class="flex flex-col gap-3">
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-200">What the engine read</h3>
                    @if($media->ocr_text === null)
                        <p class="mt-2 rounded bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-900/20 dark:text-amber-200">
                            Nothing. If the image clearly has text, this is an engine failure, not an empty screenshot — mark it <strong>Wrong</strong>.
                        </p>
                    @else
                        <pre class="mt-2 max-h-72 overflow-auto whitespace-pre-wrap rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-900">{{ $media->ocr_text }}</pre>
                    @endif
                    <p class="mt-2 text-xs text-zinc-500">{{ $media->ocr_version ?? 'no engine version' }}</p>
                </div>

                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <textarea wire:model="notes" rows="2" placeholder="Optional note — what was missed, or why it is wrong" class="w-full rounded-lg border p-2 text-sm dark:bg-zinc-900"></textarea>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach($verdicts as $verdict)
                            <button
                                wire:click="label('{{ $verdict->value }}')"
                                @class([
                                    'rounded-lg px-3 py-2 text-sm text-white',
                                    'bg-emerald-700' => $verdict->value === 'correct',
                                    'bg-amber-700' => $verdict->value === 'partial',
                                    'bg-red-700' => $verdict->value === 'wrong',
                                    'bg-zinc-600' => $verdict->value === 'no_text_in_image',
                                ])
                            >{{ $verdict->label() }}</button>
                        @endforeach
                        <button wire:click="skip" class="rounded-lg border px-3 py-2 text-sm">Skip</button>
                    </div>
                    <p class="mt-2 text-xs text-zinc-500">
                        "No text in the image" is deliberately not the same as "Correct" — keeping them apart is what stops a language the engine cannot read from scoring as success.
                    </p>
                </div>
            </div>
        </div>
    @endif
</div>
