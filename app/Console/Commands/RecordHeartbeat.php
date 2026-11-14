<?php

namespace App\Console\Commands;

use App\Jobs\QueueHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RecordHeartbeat extends Command
{
    protected $signature = 'drivecore:heartbeat';

    protected $description = 'Record scheduler heartbeat and enqueue worker heartbeat';

    public function handle(): int
    {
        Cache::put('drivecore:scheduler-heartbeat', now()->timestamp, 900);
        QueueHeartbeat::dispatch();
        $worker = Cache::get('drivecore:queue-heartbeat');
        if ($worker === null || now()->timestamp - $worker > 600) {
            Log::warning('Queue heartbeat missing or stale.');
        }

        return self::SUCCESS;
    }
}
