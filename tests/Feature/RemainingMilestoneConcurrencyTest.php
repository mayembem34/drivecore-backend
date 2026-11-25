<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Models\Invitation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RemainingMilestoneConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_competing_invitation_redemptions_wait_and_only_one_accepts(): void
    {
        $this->compete('invitation');
    }

    public function test_competing_depreciation_runs_wait_and_skip_the_posted_month(): void
    {
        $this->compete('depreciation');
    }

    private function compete(string $operation): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL row locks and independent committed connections.');
        }
        $this->seed(RolePermissionSeeder::class);
        $company = Company::factory()->create();
        $branch = Branch::factory()->create(['company_id' => $company->id]);
        $actor = User::factory()->create(['password' => 'ConcurrentPass123']);
        $actor->assignRole('company-admin');
        $token = bin2hex(random_bytes(32));
        if ($operation === 'invitation') {
            $record = Invitation::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'email' => $actor->email, 'role' => 'company-admin', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(72)]);
        } else {
            $actor->companies()->attach($company->id);
            $record = FixedAsset::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'asset_code' => 'CONCURRENT', 'name' => 'Asset', 'acquisition_cost_minor' => 120000, 'capitalisation_date' => now()->subMonth()->toDateString(), 'useful_life_months' => 12]);
        }
        $script = tempnam(sys_get_temp_dir(), 'drivecore-remaining-worker-');
        $tag = 'drivecore_remaining_'.bin2hex(random_bytes(6));
        file_put_contents($script, <<<'WORKER'
<?php
require $argv[1].'/vendor/autoload.php';
$app=require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::select("SELECT set_config('application_name', ?, false)",[$argv[6]]);
Illuminate\Support\Facades\Date::setTestNow($argv[9]);
$actor=App\Models\User::findOrFail((int)$argv[2]);
Illuminate\Support\Facades\Auth::guard('web')->setUser($actor);
$request=Illuminate\Http\Request::create('/','POST',$argv[7]==='invitation' ? ['token'=>$argv[8],'password'=>'ConcurrentPass123'] : ['company_id'=>(int)$argv[4],'period_month'=>now()->format('Y-m')]);
$request->setUserResolver(fn()=>$actor);
$session=$app->make('session')->driver(); $session->start(); $request->setLaravelSession($session);
$perform=function() use($app,$request,$argv) {
 if($argv[7]==='invitation') { return $app->make(App\Http\Controllers\Api\V1\InvitationController::class)->accept($request); }
 return $app->make(App\Http\Controllers\Api\V1\FixedAssetController::class)->depreciate($request,$app->make(App\Actions\PostJournal::class));
};
if($argv[5]==='hold') {
 Illuminate\Support\Facades\DB::beginTransaction(); $perform(); fwrite(STDOUT,"READY\n"); fflush(STDOUT); fgets(STDIN); Illuminate\Support\Facades\DB::commit(); echo "COMMITTED\n";
} else {
 try { $result=$perform(); echo $argv[7]==='depreciation' && $result->getData(true)['data']['posted_count']===0 ? "REVALIDATED\n" : "UNEXPECTED_SUCCESS\n"; }
 catch(Symfony\Component\HttpKernel\Exception\HttpException $e) { echo $e->getStatusCode()===410 ? "REVALIDATED\n" : "WRONG_STATUS\n"; }
}
WORKER);
        $config = config('database.connections.pgsql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => (string) $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'CACHE_STORE' => 'array'];
        $input = new InputStream;
        $args = [PHP_BINARY, $script, base_path(), (string) $actor->id, (string) $record->id, (string) $company->id];
        $first = new Process(array_merge($args, ['hold', $tag.'_a', $operation, $token, now()->toISOString()]), base_path(), $env, $input, 15);
        $second = new Process(array_merge($args, ['compete', $tag.'_b', $operation, $token, now()->toISOString()]), base_path(), $env, null, 15);
        try {
            $first->start();
            $this->assertTrue($first->waitUntil(fn ($type, $output) => str_contains($output, 'READY')), $first->getErrorOutput());
            $second->start();
            $blocked = false;
            $deadline = microtime(true) + 5;
            do {
                $state = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE application_name = ?', [$tag.'_b']);
                $blocked = $state?->wait_event_type === 'Lock';
                if (! $blocked) {
                    usleep(20000);
                }
            } while (! $blocked && $second->isRunning() && microtime(true) < $deadline);
            $this->assertTrue($blocked, 'Competing action must wait on the lock. '.$second->getErrorOutput());
            $input->write("release\n");
            $input->close();
            $this->assertSame(0, $first->wait(), $first->getErrorOutput());
            $this->assertSame(0, $second->wait(), $second->getErrorOutput());
            $this->assertStringContainsString('REVALIDATED', $second->getOutput());
            if ($operation === 'invitation') {
                $this->assertNotNull($record->fresh()->accepted_at);
                $this->assertSame(1, $actor->companies()->whereKey($company->id)->count());
            } else {
                $this->assertDatabaseCount('depreciation_entries', 1);
                $this->assertSame(10000, (int) DepreciationEntry::sum('amount_minor'));
                $this->assertSame(10000, $record->fresh()->accumulated_depreciation_minor);
            }
        } finally {
            $input->close();
            $first->stop();
            $second->stop();
            unlink($script);
        }
    }
}
