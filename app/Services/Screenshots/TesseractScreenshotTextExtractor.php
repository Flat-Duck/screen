<?php

namespace App\Services\Screenshots;

use App\Contracts\ScreenshotTextExtractor;
use App\Data\Screenshots\TextExtractionResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class TesseractScreenshotTextExtractor implements ScreenshotTextExtractor
{
    public function extract(string $path): TextExtractionResult
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'screenshut-ocr-');

        if ($temporaryPath === false) {
            throw new RuntimeException('OCR temporary storage is unavailable.');
        }

        try {
            $contents = Storage::disk(config('social.media_disk'))->get($path);
            if (file_put_contents($temporaryPath, $contents) === false) {
                throw new RuntimeException('OCR temporary storage is unavailable.');
            }

            $language = (string) config('social.processing.ocr.language', 'eng');
            $result = Process::timeout((int) config('social.processing.ocr.timeout_seconds', 45))
                ->run([
                    (string) config('social.processing.ocr.binary', 'tesseract'),
                    $temporaryPath,
                    'stdout',
                    '-l',
                    $language,
                    '--psm',
                    '6',
                ]);

            if (! $result->successful()) {
                // Provider output can contain recognized content. Never copy it into an
                // exception, failed_jobs, logs, or telemetry.
                throw new RuntimeException('OCR provider failed to process the screenshot.');
            }

            return new TextExtractionResult(trim($result->output()), $language);
        } finally {
            @unlink($temporaryPath);
        }
    }

    public function version(): string
    {
        return 'tesseract-v1:'.config('social.processing.ocr.language', 'eng');
    }
}
