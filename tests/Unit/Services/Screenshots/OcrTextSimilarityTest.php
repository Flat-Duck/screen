<?php

namespace Tests\Unit\Services\Screenshots;

use App\Services\Screenshots\OcrTextSimilarity;
use PHPUnit\Framework\TestCase;

class OcrTextSimilarityTest extends TestCase
{
    private OcrTextSimilarity $similarity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->similarity = new OcrTextSimilarity;
    }

    public function test_identical_text_scores_one(): void
    {
        $this->assertSame(1.0, $this->similarity->score('hello world example', 'hello world example'));
    }

    public function test_formatting_and_case_differences_do_not_count_as_disagreement(): void
    {
        // Two correct readings of the same screenshot routinely differ in whitespace, line
        // breaks and punctuation. Scoring those as errors would make the metric useless.
        $this->assertSame(1.0, $this->similarity->score("Hello,  World!\n\nExample.", 'hello world example'));
    }

    public function test_unrelated_text_scores_zero(): void
    {
        $this->assertSame(0.0, $this->similarity->score('python function exception', 'vacation beach sunset'));
    }

    public function test_partial_overlap_scores_between(): void
    {
        $score = $this->similarity->score('alpha beta gamma delta', 'alpha beta gamma epsilon');

        $this->assertGreaterThan(0.5, $score);
        $this->assertLessThan(1.0, $score);
    }

    public function test_two_empty_readings_agree(): void
    {
        // Both engines saying "there is no text here" is agreement, not a failure to compare.
        $this->assertSame(1.0, $this->similarity->score(null, ''));
        $this->assertSame(1.0, $this->similarity->score('   ', null));
    }

    public function test_one_empty_reading_is_total_disagreement(): void
    {
        $this->assertSame(0.0, $this->similarity->score('some text here', null));
    }

    public function test_arabic_tokenizes_on_the_same_footing_as_latin(): void
    {
        // An ASCII-only token class would reduce every Arabic string to zero tokens and
        // silently score all Arabic comparisons as either 1.0 or 0.0.
        $this->assertSame(1.0, $this->similarity->score('مرحبا بالعالم', 'مرحبا بالعالم'));
        $this->assertSame(0.0, $this->similarity->score('مرحبا بالعالم', 'شكرا جزيلا'));
    }
}
