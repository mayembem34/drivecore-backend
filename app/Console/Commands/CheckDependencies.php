<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckDependencies extends Command
{
    protected $signature = 'drivecore:check {--workers}';

    protected $description = 'Check database and cache readiness';

    public function handle(): int
    {
        try {
            DB::select('select 1');
            $key = 'health:'.Str::uuid();
            Cache::put($key, 'ok', 30);
            if (Cache::pull($key) !== 'ok') {
                throw new \RuntimeException('Cache check failed');
            } if ($this->option('workers')) {
                foreach (['drivecore:scheduler-heartbeat', 'drivecore:queue-heartbeat'] as $heartbeat) {
                    $last = Cache::get($heartbeat);
                    if ($last === null || now()->timestamp - $last > 600) {
                        throw new \RuntimeException('Worker heartbeat missing or stale');
                    }
                }
            } $this->info('Database and cache ready.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            Log::error('Dependency check failed', ['exception' => $exception::class]);
            $this->error('Dependency unavailable; inspect server logs.');

            return self::FAILURE;
        }
    }
}
