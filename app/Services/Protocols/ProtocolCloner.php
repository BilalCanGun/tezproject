<?php

namespace App\Services\Protocols;

use App\Models\ProtocolVersion;
use App\Models\ResourceArticle;
use App\Models\RubricCriterion;
use App\Models\Task;

/**
 * Copies the task battery, reference material and rubric from one protocol
 * version to another, e.g. when the main study starts from the revised pilot.
 */
class ProtocolCloner
{
    public function copyContent(ProtocolVersion $source, ProtocolVersion $target): void
    {
        $source->tasks()->get()->each(function (Task $task) use ($target) {
            $copy = $task->replicate(['protocol_version_id']);
            $copy->protocol_version_id = $target->id;
            $copy->save();
        });

        $source->resources()->get()->each(function (ResourceArticle $article) use ($target) {
            $copy = $article->replicate(['protocol_version_id']);
            $copy->protocol_version_id = $target->id;
            $copy->save();
        });

        $source->rubricCriteria()->get()->each(function (RubricCriterion $criterion) use ($target) {
            $copy = $criterion->replicate(['protocol_version_id']);
            $copy->protocol_version_id = $target->id;
            $copy->save();
        });
    }
}
