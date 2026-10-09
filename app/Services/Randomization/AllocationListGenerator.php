<?php

namespace App\Services\Randomization;

use App\Enums\AssistanceCondition;
use App\Enums\SkillStratum;
use App\Models\ProtocolVersion;
use App\Models\RandomizationSlot;
use Illuminate\Support\Facades\DB;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use RuntimeException;

/**
 * Builds a concealed, stratified permuted-block allocation list. Block sizes
 * are drawn at random from multiples of three so that the next assignment
 * cannot be predicted from previous ones. The same seed reproduces the list.
 */
class AllocationListGenerator
{
    /**
     * @param  list<int>  $blockSizes
     * @return array<string, int> number of slots created per stratum
     */
    public function generate(ProtocolVersion $protocol, int $slotsPerStratum, int $seed, array $blockSizes): array
    {
        foreach ($blockSizes as $size) {
            if ($size < 3 || $size % 3 !== 0) {
                throw new RuntimeException('Blok büyüklükleri 3’ün katı olmalıdır.');
            }
        }

        if ($protocol->randomizationSlots()->whereNotNull('participant_id')->exists()) {
            throw new RuntimeException('Atama yapılmış bir liste yeniden üretilemez; yalnızca uzatılabilir.');
        }

        $randomizer = new Randomizer(new Xoshiro256StarStar($seed));
        $conditions = AssistanceCondition::cases();
        $created = [];

        DB::transaction(function () use ($protocol, $slotsPerStratum, $blockSizes, $randomizer, $conditions, &$created) {
            $protocol->randomizationSlots()->delete();

            foreach (SkillStratum::cases() as $stratum) {
                $position = 0;
                $blockNumber = 0;
                $rows = [];

                while ($position < $slotsPerStratum) {
                    $blockNumber++;
                    $size = $blockSizes[$randomizer->getInt(0, count($blockSizes) - 1)];
                    $block = [];

                    foreach ($conditions as $condition) {
                        array_push($block, ...array_fill(0, intdiv($size, count($conditions)), $condition));
                    }

                    foreach ($randomizer->shuffleArray($block) as $condition) {
                        $position++;
                        $rows[] = [
                            'protocol_version_id' => $protocol->id,
                            'stratum' => $stratum->value,
                            'block_number' => $blockNumber,
                            'block_size' => $size,
                            'position' => $position,
                            'condition' => $condition->value,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }

                RandomizationSlot::insert($rows);
                $created[$stratum->value] = count($rows);
            }
        });

        return $created;
    }
}
