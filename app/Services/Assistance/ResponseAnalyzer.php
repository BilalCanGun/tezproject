<?php

namespace App\Services\Assistance;

use Illuminate\Support\Str;

/**
 * Computes the per-reply manipulation-check indicators: how much code a reply
 * contains, whether it amounts to a full solution, and how many questions it
 * asks the participant. The same function runs for both AI conditions so the
 * comparison is symmetric.
 */
class ResponseAnalyzer
{
    private const CODE_LINE_PATTERN = '/^\s*(def |return\b|for .+ in .+:|while .+:|if .+:|elif .+:|else:|[a-zA-Z_]\w*\s*(\+|-)?=\s*\S)/u';

    /**
     * @return array{word_count: int, code_blocks: int, code_lines: int, unfenced_code_lines: int, question_count: int, defines_entry_function: bool, reference_similarity: ?float, full_solution: bool}
     */
    public function analyze(string $text, ?string $entryFunction, ?string $referenceSolution, float $similarityThreshold): array
    {
        preg_match_all('/```[\w+-]*\n?(.*?)```/su', $text, $blocks);
        $code = implode("\n", $blocks[1]);
        $prose = preg_replace('/```.*?```/su', ' ', $text) ?? $text;

        $codeLines = $this->nonEmptyLines($code);
        $unfenced = count(array_filter(
            preg_split('/\R/u', $prose) ?: [],
            fn (string $line) => preg_match(self::CODE_LINE_PATTERN, $line) === 1,
        ));

        $definesEntry = $entryFunction !== null
            && preg_match('/def\s+'.preg_quote($entryFunction, '/').'\s*\(/u', $text) === 1;

        $similarity = $referenceSolution && $code !== ''
            ? $this->tokenSimilarity($code, $referenceSolution)
            : null;

        return [
            'word_count' => Str::wordCount(strip_tags($prose)),
            'code_blocks' => count($blocks[1]),
            'code_lines' => $codeLines,
            'unfenced_code_lines' => $unfenced,
            'question_count' => preg_match_all('/\?(?=\s|$|["”’)])/u', $prose),
            'defines_entry_function' => $definesEntry,
            'reference_similarity' => $similarity,
            'full_solution' => ($definesEntry && $codeLines + $unfenced >= 3)
                || ($similarity !== null && $similarity >= $similarityThreshold),
        ];
    }

    public static function entryFunctionFrom(?string $starterCode): ?string
    {
        if ($starterCode && preg_match('/def\s+(\w+)\s*\(/u', $starterCode, $match)) {
            return $match[1];
        }

        return null;
    }

    private function nonEmptyLines(string $code): int
    {
        return count(array_filter(preg_split('/\R/u', $code) ?: [], fn (string $line) => trim($line) !== ''));
    }

    /**
     * Jaccard similarity over identifier/operator tokens, ignoring comments.
     */
    private function tokenSimilarity(string $candidate, string $reference): float
    {
        $tokens = function (string $source): array {
            $source = preg_replace('/#.*$/mu', '', $source) ?? $source;
            preg_match_all('/[A-Za-z_]\w*|\d+|==|!=|<=|>=|\+=|-=|[^\s\w]/u', $source, $matches);

            return array_unique($matches[0]);
        };

        $a = $tokens($candidate);
        $b = $tokens($reference);
        $union = count(array_unique([...$a, ...$b]));

        return $union === 0 ? 0.0 : round(count(array_intersect($a, $b)) / $union, 3);
    }
}
