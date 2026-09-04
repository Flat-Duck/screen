<x-layouts::app :title="__('OCR')">
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-4">
        <div>
            <h1 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">OCR</h1>
            <p class="text-sm text-zinc-500">Extracted text is redacted by default. Revealing one costs a written reason and is recorded in the audit log.</p>
        </div>

        {{-- Pipeline health --}}
        <section class="flex flex-col gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Pipeline · last {{ $pipeline['window_days'] }} days</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-ocr-stat label="Images" :value="$pipeline['total']" hint="{{ $pipeline['ran'] }} actually ran OCR" />
                <x-ocr-stat label="Failure rate" :value="$pipeline['failure_rate'] === null ? '—' : $pipeline['failure_rate'].'%'" hint="of runs that started" />
                <x-ocr-stat label="Empty text" :value="$pipeline['empty_text_rate'] === null ? '—' : $pipeline['empty_text_rate'].'%'" hint="ran, found nothing" />
                <x-ocr-stat label="Never ran" :value="$pipeline['never_ran']" hint="seeded or device-trusted" />
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <x-ocr-stat label="Duration p50" :value="$pipeline['durations']['p50'] === null ? '—' : $pipeline['durations']['p50'].' ms'" hint="{{ $pipeline['durations']['samples'] }} timed" />
                <x-ocr-stat label="Duration p95" :value="$pipeline['durations']['p95'] === null ? '—' : $pipeline['durations']['p95'].' ms'" />
                <x-ocr-stat label="Slowest" :value="$pipeline['durations']['max'] === null ? '—' : $pipeline['durations']['max'].' ms'" />
            </div>
            <div class="grid gap-4 md:grid-cols-4">
                <x-ocr-breakdown title="Source" :counts="$pipeline['sources']" />
                <x-ocr-breakdown title="Language" :counts="$pipeline['languages']" />
                <x-ocr-breakdown title="Status" :counts="$pipeline['statuses']" />
                <x-ocr-breakdown title="Safety" :counts="$pipeline['safety']" />
            </div>
            @if(($pipeline['empty_text_rate'] ?? 0) > 30)
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                    A high empty-text rate on a screenshot app usually means the engine cannot read the language, not that the images have no text.
                    Check which Tesseract language packs are installed against the languages your users actually post in.
                </div>
            @endif
        </section>

        {{-- Device vs server --}}
        <section class="flex flex-col gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Device vs server · last {{ $accuracy['window_days'] }} days</h2>
            @if($accuracy['compared'] === 0 && $accuracy['unverified'] === 0)
                <p class="rounded-lg border border-zinc-200 p-4 text-sm text-zinc-500 dark:border-zinc-700">
                    No device-sourced OCR recorded yet. These fill in once the app publishes through the staged upload flow.
                </p>
            @else
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <x-ocr-stat label="Category agreement" :value="$accuracy['agreement_rate'] === null ? '—' : $accuracy['agreement_rate'].'%'" hint="{{ $accuracy['compared'] }} compared" />
                    <x-ocr-stat label="Token similarity" :value="$accuracy['mean_similarity'] === null ? '—' : $accuracy['mean_similarity'].'%'" hint="actual text overlap" />
                    <x-ocr-stat label="Mismatches" :value="$accuracy['mismatches']" hint="sent the author to probation" />
                    <x-ocr-stat label="Accepted unchecked" :value="$accuracy['unverified_share'] === null ? '—' : $accuracy['unverified_share'].'%'" hint="{{ $accuracy['unverified'] }} trusted claims" />
                </div>
                <p class="text-xs text-zinc-500">
                    Agreement and similarity measure different things. Agreement is whether both readings produced the same category — what the trust loop acts on.
                    Similarity is how much the text actually overlapped. High agreement with low similarity means the category test is passing readings that genuinely differ.
                </p>
                <div class="grid gap-4 md:grid-cols-2">
                    <x-ocr-breakdown title="Trust tiers" :counts="$accuracy['trust_tiers']" />
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Weekly trend</h3>
                        @forelse($curve as $week)
                            <div class="mt-2 flex items-center justify-between text-sm">
                                <span class="text-zinc-500">{{ $week['bucket'] }}</span>
                                <span>{{ $week['agreement'] === null ? '—' : $week['agreement'].'% agree' }} · {{ $week['similarity'] === null ? '—' : $week['similarity'].'% similar' }} <span class="text-xs text-zinc-500">(n={{ $week['compared'] }})</span></span>
                            </div>
                        @empty
                            <p class="mt-2 text-sm text-zinc-500">Not enough verified comparisons yet.</p>
                        @endforelse
                    </div>
                </div>
            @endif
        </section>

        {{-- Ground truth --}}
        <section class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Ground truth</h2>
                @can('manageModeration')
                    <a href="{{ route('moderation.ocr.labels') }}" class="rounded-lg bg-zinc-800 px-3 py-2 text-sm text-white">Review extractions</a>
                @endcan
            </div>
            <p class="text-sm text-zinc-500">
                Everything above measures agreement between machines, which two engines can reach by being wrong the same way — or by both reading nothing from a script neither supports.
                These are the only numbers that say whether the output is actually right.
            </p>
            @if($labelled['current'] === 0)
                <p class="rounded-lg border border-zinc-200 p-4 text-sm text-zinc-500 dark:border-zinc-700">
                    Nothing labelled yet. {{ $labelled['reviewable'] }} extraction(s) are available to review.
                </p>
            @else
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <x-ocr-stat label="Labelled accuracy" :value="$labelled['accuracy'] === null ? '—' : $labelled['accuracy'].'%'" hint="over {{ $labelled['current'] }} label(s)" />
                    <x-ocr-stat label="Coverage" :value="$labelled['coverage'] === null ? '—' : $labelled['coverage'].'%'" hint="of {{ $labelled['reviewable'] }} reviewable" />
                    <x-ocr-stat label="Wrong" :value="$labelled['verdicts']['wrong'] ?? 0" hint="{{ $labelled['verdicts']['partial'] ?? 0 }} partial" />
                    <x-ocr-stat label="Stale" :value="$labelled['stale']" hint="re-run since labelling" />
                </div>
                @if($labelled['current'] < 30)
                    <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                        Accuracy over {{ $labelled['current'] }} label(s) is not yet a number worth acting on. Treat it as a sample, not a measurement, until coverage is meaningfully higher.
                    </div>
                @endif
                <div class="grid gap-4 md:grid-cols-3">
                    <x-ocr-labelled-split title="By source" :rows="$labelled['by_source']" />
                    <x-ocr-labelled-split title="By language" :rows="$labelled['by_language']" />
                    <x-ocr-labelled-split title="By engine" :rows="$labelled['by_engine']" />
                </div>
                <p class="text-xs text-zinc-500">
                    This split is the tuning surface: it is what answers whether the device beats the server, whether adding a language pack helped,
                    and therefore whether the sample rate and trust threshold are set sensibly.
                </p>
            @endif
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Per-image results</h2>
            <livewire:ocr-media-table />
        </section>
    </div>
</x-layouts::app>
