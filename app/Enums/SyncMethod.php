<?php

namespace App\Enums;

enum SyncMethod: string
{
    case Lsl = 'lsl';
    case Sdk = 'sdk';
    case UtcPosthoc = 'utc_posthoc';

    public function label(): string
    {
        return match ($this) {
            self::Lsl => 'Lab Streaming Layer (canlı)',
            self::Sdk => 'Üretici SDK + harici marker',
            self::UtcPosthoc => 'UTC zaman damgası ile sonradan hizalama',
        };
    }

    /**
     * Typical alignment precision the analysis can rely on. Used to warn against
     * short event-locked analyses on loosely synchronised streams.
     */
    public function expectedPrecision(): string
    {
        return match ($this) {
            self::Lsl => '≈1–5 ms (LSL saat düzeltmesi sonrası)',
            self::Sdk => '≈5–20 ms (sürücü gecikmesine bağlı)',
            self::UtcPosthoc => '≈0,1–1 s (cihaz saati ve NTP sapmasına bağlı)',
        };
    }
}
