<?php

namespace App\Models;

use App\Enums\ProtocolPhase;
use Database\Factories\ProtocolVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

#[Fillable([
    'code', 'name', 'phase', 'description', 'concept_title', 'concept_rationale',
    'settings', 'system_prompt_direct', 'system_prompt_socratic', 'is_active',
])]
class ProtocolVersion extends Model
{
    /** @use HasFactory<ProtocolVersionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phase' => ProtocolPhase::class,
            'settings' => 'array',
            'is_active' => 'boolean',
            'frozen_at' => 'datetime',
        ];
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isFrozen(): bool
    {
        return $this->frozen_at !== null;
    }

    /**
     * Read a protocol setting, falling back to the configured default so older
     * versions keep working when new settings are introduced.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return Arr::get(
            $this->settings ?? [],
            $key,
            Arr::get(config('experiment.protocol_defaults'), $key, $default),
        );
    }

    /**
     * Settings merged over the defaults, used by edit forms and snapshots.
     *
     * @return array<string, mixed>
     */
    public function resolvedSettings(): array
    {
        return array_replace_recursive(config('experiment.protocol_defaults'), $this->settings ?? []);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<ResourceArticle, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(ResourceArticle::class);
    }

    /**
     * @return HasMany<RubricCriterion, $this>
     */
    public function rubricCriteria(): HasMany
    {
        return $this->hasMany(RubricCriterion::class);
    }

    /**
     * @return HasMany<Participant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    /**
     * @return HasMany<RandomizationSlot, $this>
     */
    public function randomizationSlots(): HasMany
    {
        return $this->hasMany(RandomizationSlot::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function frozenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'frozen_by');
    }
}
