<?php

namespace App\Contracts\Erp;

use App\Models\Erp\ProductionTask;

interface AssignmentScoreProvider
{
    public function score(ProductionTask $task, object $employee, array $context = []): ?float;
}
