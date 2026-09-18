<?php

namespace Tests\Support;

use Illuminate\Support\Facades\{DB, Schema};

/** Track only auto-increment rows actually inserted by this fixture/process, never table-wide data. */
final class CommittedInsertTracker
{
    public array $rows = [];

    public function listen(?callable $emit = null): void
    {
        if (! str_ends_with((string) config('database.connections.mysql.database'), '_test')
            || (int) DB::selectOne('SELECT @@auto_increment_increment AS step')->step !== 1) {
            throw new \RuntimeException('Committed fixtures require a guarded test database and increment 1.');
        }
        DB::listen(function ($query) use ($emit): void {
            // Ignore upserts/counters and non-ID pivots. MySQL reserves contiguous IDs for one ordinary INSERT.
            if (! preg_match('/^insert into `([a-z_]+)` .* values (.+)$/i', $query->sql, $match)
                || str_contains(strtolower($query->sql), 'on duplicate key')) return;
            $first = (int) DB::connection()->getPdo()->lastInsertId();
            if ($first < 1 || ! Schema::hasColumn($match[1], 'id')) return;
            $count = substr_count($match[2], '), (') + 1;
            $entry = ['table' => $match[1], 'ids' => range($first, $first + $count - 1)];
            $this->rows[] = $entry;
            if ($emit) $emit(['event' => 'inserted'] + $entry);
        });
    }

    public function cleanup(array $additional = []): void
    {
        if (! str_ends_with((string) DB::selectOne('SELECT DATABASE() AS name')->name, '_test')) {
            throw new \RuntimeException('Unsafe fixture cleanup database.');
        }
        // Two processes interleave FK dependencies, so insertion order is not a reliable topological order.
        // Disable constraints on this connection only and remove exact observed IDs, then restore immediately.
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (array_reverse(array_merge($this->rows, $additional)) as $row) {
                DB::table($row['table'])->whereIn('id', $row['ids'])->delete();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
