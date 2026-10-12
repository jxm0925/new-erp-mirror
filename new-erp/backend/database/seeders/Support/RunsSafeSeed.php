<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Facades\DB;

trait RunsSafeSeed
{
    public function run(): void
    {
        $this->preflightSchema();
        DB::transaction(fn () => $this->seed());
    }

    protected function preflightSchema(): void
    {
        SeedSafety::assertSchema();
    }
}
