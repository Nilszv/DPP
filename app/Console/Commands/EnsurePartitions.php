<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pre-creates monthly partitions for the time-partitioned tables ahead of the boundary.
 * If a partition for an incoming month does not exist, inserts would fall into the DEFAULT
 * partition (a safety net, but not where they should live). Run monthly via the scheduler.
 */
class EnsurePartitions extends Command
{
    protected $signature = 'partitions:ensure {--months=3 : How many months ahead to ensure}';

    protected $description = 'Ensure monthly partitions exist for scan_events and audit_log';

    /** Parent tables that are RANGE-partitioned by month. */
    private const TABLES = ['scan_events', 'audit_log'];

    public function handle(): int
    {
        $monthsAhead = (int) $this->option('months');
        $start = Carbon::now()->startOfMonth();
        $created = 0;

        foreach (self::TABLES as $table) {
            for ($i = 0; $i <= $monthsAhead; $i++) {
                $from = $start->copy()->addMonths($i);
                $to = $from->copy()->addMonth();
                $name = $table.'_'.$from->format('Y_m');

                if ($this->partitionExists($name)) {
                    continue;
                }

                DB::statement(sprintf(
                    'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM (%s) TO (%s)',
                    $name,
                    $table,
                    "'".$from->format('Y-m-d')."'",
                    "'".$to->format('Y-m-d')."'"
                ));

                $created++;
                $this->info("Created partition {$name}");
            }
        }

        $this->info("Partition check complete. {$created} created.");

        return self::SUCCESS;
    }

    private function partitionExists(string $name): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM pg_class WHERE relname = ? LIMIT 1',
            [$name]
        );
    }
}
