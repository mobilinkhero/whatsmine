<?php

namespace App\Console\Commands;

use App\Modules\Automation\Models\AutomationRun;
use Illuminate\Console\Command;

/**
 * An "Ask question" node parks its run as `waiting` until the contact's next
 * message. When that message never comes the run would sit there forever, so
 * runs that have waited longer than --days are cancelled with a readable reason.
 * Timed wait nodes are left alone: their delayed wake-up job is still queued.
 */
class PruneStaleAutomationRunsCommand extends Command
{
    protected $signature = 'automation:prune-stale-runs
                            {--days=30 : Cancel runs that have waited for a reply longer than this many days}';

    protected $description = 'Cancel automation runs parked on an "Ask question" node whose contact never replied';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $cancelled = 0;

        AutomationRun::where('status', 'waiting')
            ->where('updated_at', '<', $cutoff)
            ->chunkById(200, function ($runs) use (&$cancelled, $days) {
                foreach ($runs as $run) {
                    if (empty($run->context['_awaiting_reply'])) {
                        continue;
                    }

                    $run->update([
                        'status' => 'cancelled',
                        'error' => "No reply within {$days} days.",
                        'completed_at' => now(),
                    ]);
                    $cancelled++;
                }
            });

        $this->info("Cancelled {$cancelled} stale automation run(s).");

        return self::SUCCESS;
    }
}
