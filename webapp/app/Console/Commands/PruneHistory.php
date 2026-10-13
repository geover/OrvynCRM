<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneHistory extends Command
{
    protected $signature = 'orvyn:prune-history {--days=365}';

    protected $description = 'Delete old activity rows, finished automation runs and webhook deliveries in small batches.';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));
        foreach (['activities' => 'created_at', 'webhook_deliveries' => 'created_at'] as $table => $col) {
            $total = 0;
            while (($n = DB::table($table)->where($col, '<', $cutoff)->limit(5000)->delete()) > 0) {
                $total += $n;
            }
            $this->info("$table: pruned $total");
        }

        return self::SUCCESS;
    }
}
