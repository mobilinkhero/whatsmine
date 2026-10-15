<?php

namespace App\Modules\Automation\Jobs;

use App\Events\AutomationFailed;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecuteAutomationRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $runId) {}

    public function handle(AutomationEngine $engine): void
    {
        $run = AutomationRun::with('automation')->find($this->runId);
        // A finished run must never execute again — e.g. a delayed wake-up job for a
        // run that was completed or cancelled while it sat in the queue.
        if (! $run || in_array($run->status, ['cancelled', 'failed', 'completed'], true)) {
            return;
        }

        try {
            $engine->executeRun($run);
        } catch (\Throwable $e) {
            // The column is `error` (shown on the Runs page). Writing to a
            // non-existent `error_message` key was silently dropped, which left
            // every crashed run as "failed" with no reason.
            $run->update(['status' => 'failed', 'error' => $e->getMessage(), 'completed_at' => now()]);
            AutomationFailed::dispatch($run, $e->getMessage());
            throw $e;
        }
    }
}
