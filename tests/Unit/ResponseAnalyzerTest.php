<?php

namespace Tests\Unit;

use App\Services\Assistance\ResponseAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ResponseAnalyzerTest extends TestCase
{
    private const REFERENCE = "def toplam(veri):\n    sonuc = 0\n    for e in veri:\n        if isinstance(e, list):\n            sonuc += toplam(e)\n        else:\n            sonuc += e\n    return sonuc\n";

    #[Test]
    public function a_fenced_complete_function_is_a_full_solution(): void
    {
        $metrics = (new ResponseAnalyzer)->analyze("İşte çözüm:\n```python\n".self::REFERENCE.'```', 'toplam', self::REFERENCE, 0.55);

        $this->assertTrue($metrics['full_solution']);
        $this->assertTrue($metrics['defines_entry_function']);
        $this->assertSame(1, $metrics['code_blocks']);
        $this->assertSame(8, $metrics['code_lines']);
    }

    #[Test]
    public function unfenced_code_is_still_counted(): void
    {
        $metrics = (new ResponseAnalyzer)->analyze("Şöyle yazabilirsin:\ndef toplam(veri):\n    sonuc = 0\n    return sonuc", 'toplam', self::REFERENCE, 0.55);

        $this->assertSame(0, $metrics['code_lines']);
        $this->assertGreaterThanOrEqual(3, $metrics['unfenced_code_lines']);
        $this->assertTrue($metrics['full_solution']);
    }

    #[Test]
    public function a_socratic_question_is_not_a_solution(): void
    {
        $metrics = (new ResponseAnalyzer)->analyze('Bir eleman liste olduğunda ne yapmalısın? Temel durumun ne?', 'toplam', self::REFERENCE, 0.55);

        $this->assertFalse($metrics['full_solution']);
        $this->assertSame(2, $metrics['question_count']);
        $this->assertSame(0, $metrics['code_lines'] + $metrics['unfenced_code_lines']);
    }

    #[Test]
    public function the_entry_function_is_read_from_the_starter_code(): void
    {
        $this->assertSame('cift_say', ResponseAnalyzer::entryFunctionFrom("def cift_say(veri):\n    pass"));
        $this->assertNull(ResponseAnalyzer::entryFunctionFrom(null));
    }
}
