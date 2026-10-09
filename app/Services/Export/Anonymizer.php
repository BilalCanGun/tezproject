<?php

namespace App\Services\Export;

use App\Models\ParticipantContact;

/**
 * Removes direct identifiers that participants may type into chats, code or
 * explanations before free text leaves the system. Pattern-based scrubbing is
 * a safety net, not a guarantee; shared datasets still get a manual check.
 */
class Anonymizer
{
    /** @var list<string>|null */
    private ?array $knownNames = null;

    public function scrub(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[E-POSTA]', $text) ?? $text;
        $text = preg_replace('/\b[1-9]\d{10}\b/u', '[KIMLIK_NO]', $text) ?? $text;
        $text = preg_replace('/(\+?90[\s-]?)?\(?0?5\d{2}\)?[\s-]?\d{3}[\s-]?\d{2}[\s-]?\d{2}/u', '[TELEFON]', $text) ?? $text;
        $text = preg_replace('#https?://\S+#u', '[URL]', $text) ?? $text;

        foreach ($this->knownNames() as $name) {
            $text = preg_replace('/\b'.preg_quote($name, '/').'\b/iu', '[AD]', $text) ?? $text;
        }

        return $text;
    }

    /**
     * Name parts (≥3 letters) of every enrolled participant, so a participant
     * writing their own or a classmate's name is caught.
     *
     * @return list<string>
     */
    private function knownNames(): array
    {
        return $this->knownNames ??= ParticipantContact::query()
            ->whereNotNull('full_name')
            ->get(['id', 'full_name'])
            ->flatMap(fn (ParticipantContact $contact) => preg_split('/\s+/u', trim((string) $contact->full_name)) ?: [])
            ->filter(fn (string $part) => mb_strlen($part) >= 3)
            ->unique()
            ->sortByDesc(fn (string $part) => mb_strlen($part))
            ->values()
            ->all();
    }
}
