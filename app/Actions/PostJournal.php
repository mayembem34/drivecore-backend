<?php

namespace App\Actions;

use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PostJournal
{
    public static function seed(int $companyId): void
    {
        $rows = [];
        foreach (['1000' => ['Cash & Bank', 'asset'], '1100' => ['Accounts Receivable', 'asset'], '2100' => ['VAT Payable', 'liability'], '4000' => ['Rental Revenue', 'revenue'], '4100' => ['Sales Refunds & Credits', 'revenue'], '5000' => ['Fleet & Operating Expenses', 'expense']] as $code => $account) {
            $rows[] = ['company_id' => $companyId, 'code' => (string) $code, 'name' => $account[0], 'type' => $account[1], 'is_system' => true, 'created_at' => now(), 'updated_at' => now()];
        }
        GlAccount::upsert($rows, ['company_id', 'code'], ['code']);
    }

    public function handle(User $user, int $companyId, int $branchId, string $source, int $sourceId, array $lines, string $reference, string $memo): JournalEntry
    {
        return DB::transaction(function () use ($user, $companyId, $branchId, $source, $sourceId, $lines, $reference, $memo): JournalEntry {
            if (array_sum(array_column($lines, 1)) !== array_sum(array_column($lines, 2))) {
                throw new \LogicException('Journal is not balanced.');
            }
            self::seed($companyId);
            $entry = JournalEntry::create(['company_id' => $companyId, 'branch_id' => $branchId, 'created_by' => $user->id, 'source_type' => $source, 'source_id' => $sourceId, 'reference' => $reference, 'memo' => $memo, 'posted_at' => now()]);
            foreach ($lines as [$code,$debit,$credit]) {
                if ($debit < 0 || $credit < 0) {
                    throw new \LogicException('Negative journal amount.');
                }if ($debit === 0 && $credit === 0) {
                    continue;
                }$account = GlAccount::where('company_id', $companyId)->where('code', $code)->firstOrFail();
                $entry->lines()->create(['gl_account_id' => $account->id, 'debit_minor' => $debit, 'credit_minor' => $credit]);
            }

            return $entry;
        }, 3);
    }
}
