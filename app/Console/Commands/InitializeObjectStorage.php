<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InitializeObjectStorage extends Command
{
    protected $signature = 'drivecore:check-storage';

    protected $description = 'Verify configured private object storage with a disposable probe';

    public function handle(): int
    {
        $disk = Storage::disk('s3');
        $path = 'health/'.Str::uuid();
        try {
            $disk->put($path, 'drivecore-storage-check', ['visibility' => 'private']);
            if ($disk->get($path) !== 'drivecore-storage-check') {
                throw new \RuntimeException('Storage probe mismatch');
            } $this->info('Private object storage read/write verified.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Object storage check failed; inspect configuration and provider permissions.');

            return self::FAILURE;
        } finally {
            try {
                $disk->delete($path);
            } catch (\Throwable) {
            }
        }
    }
}
