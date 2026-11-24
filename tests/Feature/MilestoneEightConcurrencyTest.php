<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MilestoneEightConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_competing_payments_wait_and_reject_overpayment_after_commit(): void
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
        $invoice = Invoice::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'created_by' => $actor->id, 'invoice_number' => 'CONCURRENT', 'custom_customer_name' => 'Customer', 'status' => 'issued', 'due_at' => now()->addDay(), 'subtotal_minor' => 1000, 'total_minor' => 1000, 'vat_minor' => 0, 'vat_rate_pct' => 0]);
        $script = tempnam(sys_get_temp_dir(), 'drivecore-m8-worker-');
        $tag = 'drivecore_m8_'.bin2hex(random_bytes(6));
        file_put_contents($script, <<<'WORKER'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::select("SELECT set_config('application_name', ?, false)", [$argv[6]]);
$actor = App\Models\User::findOrFail((int) $argv[2]);
$controller = $app->make(App\Http\Controllers\Api\V1\InvoiceController::class);
Illuminate\Support\Facades\Auth::guard('web')->setUser($actor);
$request = Illuminate\Http\Request::create('/', 'POST', ['amount_minor' => 600, 'method' => 'cash', 'reference' => $argv[5]]);
$request->setUserResolver(fn () => $actor);
$journal = $app->make(App\Actions\PostJournal::class);
if ($argv[5] === 'hold') {
    Illuminate\Support\Facades\DB::beginTransaction();
    $controller->settlement($request, (int) $argv[3], 'payments', $journal);
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    fgets(STDIN);
    Illuminate\Support\Facades\DB::commit();
    echo "COMMITTED\n";
} else {
    try {
        $controller->settlement($request, (int) $argv[3], 'payments', $journal);
        echo "UNEXPECTED_SUCCESS\n";
    } catch (Illuminate\Validation\ValidationException $exception) {
        echo array_key_exists('amount_minor', $exception->errors()) ? "REVALIDATED\n" : "WRONG_VALIDATION\n";
    }
}
WORKER);
        $config = config('database.connections.pgsql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => (string) $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'CACHE_STORE' => 'array'];
        $input = new InputStream;
        $first = new Process([PHP_BINARY, $script, base_path(), (string) $actor->id, (string) $invoice->id, 'unused', 'hold', $tag.'_a'], base_path(), $env, $input, 15);
        $second = new Process([PHP_BINARY, $script, base_path(), (string) $actor->id, (string) $invoice->id, 'unused', 'compete', $tag.'_b'], base_path(), $env, null, 15);
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
            $this->assertTrue($blocked, 'The competing payment must wait for the locked invoice. '.$second->getErrorOutput());
            $input->write("release\n");
            $input->close();
            $this->assertSame(0, $first->wait(), $first->getErrorOutput());
            $this->assertSame(0, $second->wait(), $second->getErrorOutput());
            $this->assertStringContainsString('REVALIDATED', $second->getOutput());
            $this->assertSame(600, (int) Payment::where('invoice_id', $invoice->id)->sum('amount_minor'));
            $this->assertDatabaseCount('journal_entries', 1);
            $this->assertDatabaseCount('audit_events', 1);
        } finally {
            $input->close();
            $first->stop();
            $second->stop();
            unlink($script);
        }
    }
}
