<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Rental;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MilestoneSixConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_competing_extensions_wait_and_revalidate_the_committed_end(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL row locks and independent committed connections.');
        }
        $this->seed(RolePermissionSeeder::class);
        $company = Company::factory()->create();
        $branch = Branch::factory()->create(['company_id' => $company->id]);
        $actor = User::factory()->create();
        $actor->assignRole('company-admin');
        $actor->companies()->attach($company->id);
        $customer = Customer::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
        $vehicle = Vehicle::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'status' => 'rented']);
        $end = now()->startOfSecond()->addDay();
        $reservation = Reservation::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'created_by' => $actor->id, 'starts_at' => now(), 'ends_at' => $end]);
        $rental = Rental::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id, 'reservation_id' => $reservation->id, 'status' => 'active']);
        $script = tempnam(sys_get_temp_dir(), 'drivecore-m6-worker-');
        $tag = 'drivecore_m6_'.bin2hex(random_bytes(6));
        file_put_contents($script, <<<'WORKER'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::select("SELECT set_config('application_name', ?, false)", [$argv[6]]);
$actor = App\Models\User::findOrFail((int) $argv[2]);
$action = $app->make(App\Actions\TransitionRental::class);
if ($argv[5] === 'hold') {
    Illuminate\Support\Facades\DB::beginTransaction();
    $action->extend($actor, (int) $argv[3], ['ends_at' => $argv[4]]);
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    fgets(STDIN);
    Illuminate\Support\Facades\DB::commit();
    echo "COMMITTED\n";
} else {
    try {
        $action->extend($actor, (int) $argv[3], ['ends_at' => $argv[4]]);
        echo "UNEXPECTED_SUCCESS\n";
    } catch (Illuminate\Validation\ValidationException $exception) {
        echo array_key_exists('ends_at', $exception->errors()) ? "REVALIDATED\n" : "WRONG_VALIDATION\n";
    }
}
WORKER);
        $config = config('database.connections.pgsql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => (string) $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'CACHE_STORE' => 'array'];
        $input = new InputStream;
        $first = new Process([PHP_BINARY, $script, base_path(), (string) $actor->id, (string) $rental->id, $end->copy()->addDays(2)->toIso8601String(), 'hold', $tag.'_a'], base_path(), $env, $input, 15);
        $second = new Process([PHP_BINARY, $script, base_path(), (string) $actor->id, (string) $rental->id, $end->copy()->addDay()->toIso8601String(), 'compete', $tag.'_b'], base_path(), $env, null, 15);
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
            $this->assertTrue($blocked, 'The competing extension must wait for the locked vehicle. '.$second->getErrorOutput());
            $input->write("release\n");
            $input->close();
            $this->assertSame(0, $first->wait(), $first->getErrorOutput());
            $this->assertSame(0, $second->wait(), $second->getErrorOutput());
            $this->assertStringContainsString('REVALIDATED', $second->getOutput());
            $this->assertTrue($reservation->fresh()->ends_at->eq($end->copy()->addDays(2)));
            $this->assertDatabaseCount('audit_events', 1);
        } finally {
            $input->close();
            $first->stop();
            $second->stop();
            unlink($script);
        }
    }
}
