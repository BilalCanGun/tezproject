<?php

namespace App\Services\Analytics;

/**
 * Small, dependency-free statistics needed by the in-app reports. Anything
 * inferential beyond reliability belongs in the preregistered R/Python
 * pipeline that consumes the exports.
 */
class Statistics
{
    /**
     * @param  list<float|int>  $values
     */
    public static function mean(array $values): ?float
    {
        return $values ? array_sum($values) / count($values) : null;
    }

    /**
     * @param  list<float|int>  $values
     */
    public static function median(array $values): ?float
    {
        if (! $values) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * @param  list<float|int>  $values
     */
    public static function sd(array $values): ?float
    {
        if (count($values) < 2) {
            return null;
        }

        $mean = self::mean($values);

        return sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / (count($values) - 1));
    }

    /**
     * One-way random-effects ICC(1,1). Appropriate when each target is rated
     * by a different, randomly drawn pair of raters, as in the rating queue.
     *
     * @param  list<list<float>>  $matrix  rows = targets, columns = ratings
     */
    public static function iccOneWay(array $matrix): ?float
    {
        $n = count($matrix);
        $k = $n ? count($matrix[0]) : 0;

        if ($n < 3 || $k < 2) {
            return null;
        }

        $grand = self::mean(array_merge(...$matrix));
        $ssBetween = 0.0;
        $ssWithin = 0.0;

        foreach ($matrix as $row) {
            $rowMean = self::mean($row);
            $ssBetween += $k * ($rowMean - $grand) ** 2;

            foreach ($row as $value) {
                $ssWithin += ($value - $rowMean) ** 2;
            }
        }

        $msBetween = $ssBetween / ($n - 1);
        $msWithin = $ssWithin / ($n * ($k - 1));
        $denominator = $msBetween + ($k - 1) * $msWithin;

        return $denominator == 0.0 ? null : round(($msBetween - $msWithin) / $denominator, 3);
    }

    /**
     * Two-way random-effects, absolute-agreement ICC(2,1). Used when the two
     * "raters" are fixed sources, e.g. LLM score vs. mean human score.
     *
     * @param  list<list<float>>  $matrix
     */
    public static function iccTwoWayAgreement(array $matrix): ?float
    {
        $n = count($matrix);
        $k = $n ? count($matrix[0]) : 0;

        if ($n < 3 || $k < 2) {
            return null;
        }

        $grand = self::mean(array_merge(...$matrix));
        $rowMeans = array_map(fn ($row) => self::mean($row), $matrix);
        $colMeans = array_map(fn ($c) => self::mean(array_column($matrix, $c)), range(0, $k - 1));

        $ssRows = $k * array_sum(array_map(fn ($m) => ($m - $grand) ** 2, $rowMeans));
        $ssCols = $n * array_sum(array_map(fn ($m) => ($m - $grand) ** 2, $colMeans));
        $ssTotal = array_sum(array_map(fn ($v) => ($v - $grand) ** 2, array_merge(...$matrix)));
        $ssError = $ssTotal - $ssRows - $ssCols;

        $msRows = $ssRows / ($n - 1);
        $msCols = $ssCols / ($k - 1);
        $msError = $ssError / (($n - 1) * ($k - 1));
        $denominator = $msRows + ($k - 1) * $msError + $k * ($msCols - $msError) / $n;

        return $denominator == 0.0 ? null : round(($msRows - $msError) / $denominator, 3);
    }

    /**
     * Cohen's kappa with quadratic weights for ordinal rubric scores.
     *
     * @param  list<array{0: int, 1: int}>  $pairs
     */
    public static function weightedKappa(array $pairs, int $min, int $max): ?float
    {
        $categories = $max - $min + 1;

        if (count($pairs) < 3 || $categories < 2) {
            return null;
        }

        $observed = array_fill(0, $categories, array_fill(0, $categories, 0));

        foreach ($pairs as [$a, $b]) {
            $observed[$a - $min][$b - $min]++;
        }

        $total = count($pairs);
        $rowTotals = array_map('array_sum', $observed);
        $colTotals = array_map(fn ($j) => array_sum(array_column($observed, $j)), range(0, $categories - 1));

        $weightedObserved = 0.0;
        $weightedExpected = 0.0;

        for ($i = 0; $i < $categories; $i++) {
            for ($j = 0; $j < $categories; $j++) {
                $weight = (($i - $j) ** 2) / (($categories - 1) ** 2);
                $weightedObserved += $weight * $observed[$i][$j] / $total;
                $weightedExpected += $weight * ($rowTotals[$i] * $colTotals[$j]) / ($total ** 2);
            }
        }

        return $weightedExpected == 0.0 ? null : round(1 - $weightedObserved / $weightedExpected, 3);
    }

    public static function interpretReliability(?float $value): string
    {
        return match (true) {
            $value === null => 'Yetersiz veri',
            $value < 0.5 => 'Zayıf',
            $value < 0.75 => 'Orta',
            $value < 0.9 => 'İyi',
            default => 'Mükemmel',
        };
    }
}
