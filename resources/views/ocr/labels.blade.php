<x-layouts::app :title="__('OCR review')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl p-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">OCR review</h1>
                <p class="text-sm text-zinc-500">Compare what the engine read against the screenshot. Every verdict is recorded against you and against this exact extraction.</p>
            </div>
            <a href="{{ route('moderation.ocr.index') }}" class="text-sm text-blue-600">Back to OCR</a>
        </div>
        <livewire:ocr-label-review />
    </div>
</x-layouts::app>
