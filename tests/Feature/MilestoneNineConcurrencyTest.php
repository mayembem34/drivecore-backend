<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Part;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MilestoneNineConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_competing_receipts_wait_and_reject_duplicate_stock_receipt(): void
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
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Vendor']);
        $part = Part::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'sku' => 'LOCK', 'name' => 'Part', 'unit_cost_minor' => 100]);
        $order = PurchaseOrder::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'created_by' => $actor->id, 'po_number' => 'CONCURRENT', 'status' => 'approved', 'total_minor' => 500]);
        $order->lines()->create(['part_id' => $part->id, 'quantity' => 5, 'unit_cost_minor' => 100, 'line_total_minor' => 500]);
        $script = tempnam(sys_get_temp_dir(), 'drivecore-m9-worker-');
        $tag = 'drivecore_m9_'.bin2hex(random_bytes(6));
        file_put_contents($script, <<<'WORKER'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::select("SELECT set_config('application_name', ?, false)", [$argv[6]]);
$actor = App\Models\User::findOrFail((int) $argv[2]);
$controller = $app->make(App\Http\Controllers\Api\V1\PurchaseOrderController::class);
Illuminate\Support\Facades\Auth::guard('web')->setUser($actor);
$request = Illuminate\Http\Request::create('/', 'POST', []);
$request->setUserResolver(fn () => $actor);
$journal = $app->make(App\Actions\PostJournal::class);
if ($argv[5] === 'hold') {
    Illuminate\Support\Facades\DB::beginTransaction();
    $controller->transition($request, (int) $argv[3], 'receive', $app->make(App\Actions\MoveStock::class), $journal);
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    fgets(STDIN);
    Illuminate\Support\Facades\DB::commit();
    echo "COMMITTED\n";
} else {
    try {
        $controller->transition($request, (int) $argv[3], 'receive', $app->make(App\Actions\MoveStock::class), $journal);
        echo "UNEXPECTED_SUCCESS\n";
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        echo $exception->getStatusCode() === 409 ? "REVALIDATED\n" : "WRONG_VALIDATION\n";
    }
}
WORKER);
        $config = config('database.connections.pgsql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => (string) $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'CACHE_STORE' => 'array'];
        $input = new InputStream;
        $first = new Process([PHP_BINARY, $script, base_path(), (string) $actor->id, (string) $order->id, 'unused', 'hold', $tag.'_a'], base_path(), $env, $input, 15);
        $second = new Process([PHP_BINARY, $script, base_path(), (string) $actor->id, (string) $order->id, 'unused', 'compete', $tag.'_b'], base_path(), $env, null, 15);
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
            $this->assertTrue($blocked, 'The competing receipt must wait for the locked purchase order. '.$second->getErrorOutput());
            $input->write("release\n");
            $input->close();
            $this->assertSame(0, $first->wait(), $first->getErrorOutput());
            $this->assertSame(0, $second->wait(), $second->getErrorOutput());
            $this->assertStringContainsString('REVALIDATED', $second->getOutput());
            $this->assertSame(5, (int) StockMovement::where('purchase_order_id', $order->id)->sum('quantity'));
            $this->assertDatabaseCount('stock_movements', 1);
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
