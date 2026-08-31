<?php

use App\Services\ImageSafetyInspector;
use Illuminate\Support\Facades\Storage;

test('image inspector rejects extreme pixel dimensions before full decode', function () {
    Storage::fake('r2');
    config()->set('social.images.max_dimension', 12000);
    config()->set('social.images.max_pixels', 40000000);

    // Enough of a PNG header for getimagesize()/finfo to identify a 50k x 50k image. The CRC is
    // deliberately irrelevant: dimension rejection happens before any full decoder sees it.
    $bytes = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NNCCCCC', 50000, 50000, 8, 2, 0, 0, 0).pack('N', 0);
    Storage::disk('r2')->put('bomb.png', $bytes);

    expect(fn () => app(ImageSafetyInspector::class)->inspectObject(Storage::disk('r2'), 'bomb.png'))
        ->toThrow(InvalidArgumentException::class, 'dimensions exceed');
});
