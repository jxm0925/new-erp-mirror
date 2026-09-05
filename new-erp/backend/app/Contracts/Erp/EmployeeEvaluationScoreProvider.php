<?php

namespace App\Contracts\Erp;

interface EmployeeEvaluationScoreProvider
{
    public function getEvaluationScore(object $employee, array $context): ?float;
}
