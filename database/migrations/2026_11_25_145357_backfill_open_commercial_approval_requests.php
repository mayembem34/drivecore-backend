<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['quotations' => ['pending_approval', 'discount', 'quotation', 'discount_pct'], 'purchase_orders' => ['submitted', 'purchase', 'purchase_order', 'total_minor']] as $table => [$status,$type,$entity,$value]) {
            DB::table($table)->where('status', $status)->whereNotExists(function ($q) use ($table, $type, $entity): void {
                $q->selectRaw('1')->from('approval_requests')->whereColumn('approval_requests.entity_id', $table.'.id')->where('approval_requests.entity_type', $entity)->where('approval_requests.type', $type)->where('approval_requests.status', 'pending');
            })->orderBy('id')->eachById(function (object $row) use ($type, $entity, $value): void {
                DB::table('approval_requests')->insert(['company_id' => $row->company_id, 'branch_id' => $row->branch_id, 'type' => $type, 'entity_type' => $entity, 'entity_id' => $row->id, 'title' => 'Existing '.str_replace('_', ' ', $entity).' #'.$row->id, 'requester_id' => $row->created_by, 'requested_value' => (string) $row->$value, 'reason' => 'Existing pending commercial approval migrated to the shared approval engine.', 'status' => 'pending', 'created_at' => $row->created_at, 'updated_at' => now()]);
            });
        }
    }

    public function down(): void {}
};
