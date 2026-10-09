<?php

namespace App\Enums;

enum TaskKind: string
{
    case Baseline = 'baseline';
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
            self::Baseline, self::DelayedRecall => 'mcq',
            self::Explanation => 'text',
            default => 'code',
        };
    }

    public function isCoding(): bool
    {
        return $this->responseFormat() === 'code';
    }
}
