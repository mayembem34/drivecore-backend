<?php

namespace App\Actions;

use App\Models\Part;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class MoveStock
{
    public function handle(User $user, Part $part, int $quantity, int $unitCost, string $reason, ?int $workOrder = null, ?int $purchaseOrder = null): StockMovement
    {
        if ($quantity === 0 || $part->onHand() + $quantity < 0) {
            throw ValidationException::withMessages(['quantity' => 'Stock quantity cannot be zero or result in negative stock.']);
        }

        return StockMovement::create(['company_id' => $part->company_id, 'branch_id' => $part->branch_id, 'part_id' => $part->id, 'recorded_by' => $user->id, 'quantity' => $quantity, 'unit_cost_minor' => $unitCost, 'reason' => $reason, 'work_order_id' => $workOrder, 'purchase_order_id' => $purchaseOrder]);
    }
}
