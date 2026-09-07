<?php

namespace Tests\Unit\Support\Media;

use App\Support\Media\ThumbHash;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ThumbHash is a wire format shared with a separate Kotlin decoder in the Android app, so "looks
 * plausible" is not a passing grade — a port that is merely close produces subtly wrong colours on
 * every image in the product and fails nowhere a human would notice.
 *
 * The vectors in tests/Fixtures/thumbhash-reference-vectors.json were produced by running Evan
 * Wallace's reference `rgbaToThumbHash` (evanw/thumbhash, MIT) directly, over deterministic
 * pseudo-random pixels across portrait, landscape, square and single-axis shapes, with and without
 * alpha. Regenerate them from the reference, never from this implementation — a fixture generated
 * from the code it tests proves only that the code has not changed.
 */
class ThumbHashTest extends TestCase
{
    /** @return list<array{0: string, 1: int, 2: int, 3: list<int>, 4: string}> */
    public static function referenceVectors(): array
    {
        $path = __DIR__.'/../../../Fixtures/thumbhash-reference-vectors.json';
        $cases = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return array_map(
            static fn (array $c): array => ["{$c['w']}x{$c['h']} {$c['mode']}", $c['w'], $c['h'], $c['rgba'], $c['expected']],
            $cases,
        );
    }

    #[DataProvider('referenceVectors')]
    public function test_it_matches_the_reference_implementation_byte_for_byte(
        string $label,
        int $width,
        int $height,
        array $rgba,
        string $expected,
    ): void {
        $this->assertSame($expected, ThumbHash::encodeToBase64($width, $height, $rgba), $label);
    }

    /**
     * The one documented deviation from the reference, and why it is safe.
     *
     * A uniform image's AC terms are mathematically zero but arrive as floating-point residues
     * around 1e-17. The reference normalizes by the largest of them, amplifying pure noise by
     * ~1e16, so it produces different bytes on different engines — it disagrees with itself. This
     * implementation treats a negligible scale as zero instead.
     *
     * That cannot change any rendered image: the scale is stored as round(31 * scale), which is
     * already 0 at this magnitude, and every decoder multiplies its AC coefficients by it. The
     * bytes that differ are multiplied by zero.
     */
    public function test_a_uniform_image_encodes_deterministically_with_inert_coefficients(): void
    {
        $red = array_merge(...array_fill(0, 7 * 7, [255, 0, 0, 255]));

        $hash = ThumbHash::encode(7, 7, $red);

        $this->assertSame($hash, ThumbHash::encode(7, 7, $red), 'the same pixels must encode identically');

        $bytes = array_values(unpack('C*', $hash));
        $header24 = $bytes[0] | ($bytes[1] << 8) | ($bytes[2] << 16);
        $header16 = $bytes[3] | ($bytes[4] << 8);

        // Every channel's stored scale is zero, which is what makes the AC bytes inert.
        $this->assertSame(0, ($header24 >> 18) & 31, 'luminance scale');
        $this->assertSame(0, ($header16 >> 3) & 63, 'p scale');
        $this->assertSame(0, ($header16 >> 9) & 63, 'q scale');
        $this->assertSame(array_fill(0, count($bytes) - 5, 0), array_slice($bytes, 5), 'AC bytes');
    }

    /** The average colour is the part a viewer actually sees, so it has to survive. */
    public function test_the_average_colour_is_recoverable_from_the_header(): void
    {
        $hash = ThumbHash::encode(4, 4, array_merge(...array_fill(0, 16, [255, 0, 0, 255])));
        $bytes = array_values(unpack('C*', $hash));
        $header24 = $bytes[0] | ($bytes[1] << 8) | ($bytes[2] << 16);

        // Decoder side of the format: l_dc/63, p_dc/31.5-1, q_dc/31.5-1. Pure red is dark in
        // luminance, neutral-to-yellow in p, and hard positive in q (red minus green).
        $this->assertEqualsWithDelta(1 / 3, ($header24 & 63) / 63, 0.02);
        $this->assertEqualsWithDelta(0.5, (($header24 >> 6) & 63) / 31.5 - 1, 0.05);
        $this->assertEqualsWithDelta(1.0, (($header24 >> 12) & 63) / 31.5 - 1, 0.05);
        $this->assertSame(0, $header24 >> 23, 'an opaque image must not set the alpha flag');
    }

    public function test_an_image_with_transparency_sets_the_alpha_flag_and_grows_the_hash(): void
    {
        $pixels = static fn (int $alpha): array => array_merge(...array_fill(0, 16, [10, 200, 90, $alpha]));

        $opaque = ThumbHash::encode(4, 4, $pixels(255));
        $translucent = ThumbHash::encode(4, 4, $pixels(128));

        $flag = static fn (string $h): int => (ord($h[0]) | (ord($h[1]) << 8) | (ord($h[2]) << 16)) >> 23;
        $this->assertSame(0, $flag($opaque));
        $this->assertSame(1, $flag($translucent));
        // Alpha costs a dedicated byte for its DC/scale plus a whole 5x5 coefficient channel.
        $this->assertGreaterThan(strlen($opaque), strlen($translucent));
    }

    public function test_it_refuses_an_image_larger_than_the_format_allows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scale it down first');

        ThumbHash::encode(101, 10, array_fill(0, 101 * 10 * 4, 0));
    }

    public function test_it_refuses_a_pixel_buffer_that_does_not_match_the_dimensions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected 64 RGBA components');

        ThumbHash::encode(4, 4, array_fill(0, 60, 0));
    }
}
