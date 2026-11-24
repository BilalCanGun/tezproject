<?php

namespace App\Enums;

enum TaskKind: string
{
    case Baseline = 'baseline';
    case ConceptPrecheck = 'concept_precheck';
    case Learning = 'learning';
    case NearTransfer = 'near_transfer';
    case FarTransfer = 'far_transfer';
    case Debugging = 'debugging';
    case DelayedRecall = 'delayed_recall';
    case Explanation = 'explanation';

    public function label(): string
    {
        return match ($this) {
            self::Baseline => 'Ön test maddesi',
            self::ConceptPrecheck => 'Kavram ön kontrolü',
            self::Learning => 'Öğrenme görevi',
            self::NearTransfer => 'Yakın transfer',
            self::FarTransfer => 'Uzak transfer',
            self::Debugging => 'Hata bulma ve düzeltme',
            self::DelayedRecall => 'Gecikmeli hatırlama',
            self::Explanation => 'Açıklama',
        };
    }

    /**
     * How the participant answers: multiple choice, code, or free text.
     */
    public function responseFormat(): string
    {
        return match ($this) {
            self::Baseline, self::ConceptPrecheck, self::DelayedRecall => 'mcq',
            self::Explanation => 'text',
            default => 'code',
        };
    }

    /**
     * Kinds answered in the pre-assessment (session 0). They never count as
     * learning outcomes.
     */
    public function isPreAssessment(): bool
    {
        return in_array($this, [self::Baseline, self::ConceptPrecheck], true);
    }

    public function isCoding(): bool
    {
        return $this->responseFormat() === 'code';
    }
}
