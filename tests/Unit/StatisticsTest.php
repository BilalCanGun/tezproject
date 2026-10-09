<?php

namespace Tests\Unit;

use App\Services\Analytics\Statistics;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StatisticsTest extends TestCase
{
    #[Test]
    public function perfect_agreement_gives_an_icc_of_one(): void
    {
        $this->assertSame(1.0, Statistics::iccOneWay([[1, 1], [2, 2], [3, 3], [4, 4]]));
        $this->assertSame(1.0, Statistics::iccTwoWayAgreement([[1, 1], [2, 2], [3, 3], [4, 4]]));
    }

    #[Test]
    public function a_constant_offset_lowers_absolute_agreement(): void
    {
        $icc = Statistics::iccTwoWayAgreement([[1, 2], [2, 3], [3, 4], [4, 5]]);

        $this->assertLessThan(1.0, $icc);
        $this->assertGreaterThan(0.5, $icc);
    }

    #[Test]
    public function one_way_icc_matches_a_hand_computed_value(): void
    {
        // Row means 3, 5, 7 (grand 5): MS_between = 16 / 2 = 8, MS_within = 6 / 3 = 2,
        // ICC(1,1) = (8 - 2) / (8 + 2) = 0.6.
        $this->assertSame(0.6, Statistics::iccOneWay([[2, 4], [4, 6], [6, 8]]));
    }

    #[Test]
    public function weighted_kappa_is_one_for_identical_ratings_and_lower_for_disagreement(): void
    {
        $this->assertSame(1.0, Statistics::weightedKappa([[0, 0], [1, 1], [2, 2], [3, 3]], 0, 3));

        $close = Statistics::weightedKappa([[0, 1], [1, 1], [2, 3], [3, 3], [2, 2]], 0, 3);
        $far = Statistics::weightedKappa([[0, 3], [1, 1], [3, 0], [3, 3], [2, 2]], 0, 3);

        $this->assertGreaterThan($far, $close);
    }

    #[Test]
    public function too_little_data_returns_null(): void
    {
        $this->assertNull(Statistics::iccOneWay([[1, 2], [2, 3]]));
        $this->assertNull(Statistics::weightedKappa([[1, 1]], 0, 3));
        $this->assertNull(Statistics::sd([1]));
    }

    #[Test]
    public function median_handles_even_and_odd_counts(): void
    {
        $this->assertSame(2.0, Statistics::median([3, 1, 2]));
        $this->assertSame(2.5, Statistics::median([4, 1, 2, 3]));
    }
}
