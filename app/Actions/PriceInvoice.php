<?php

namespace App\Actions;

use App\Models\Invoice;
use Illuminate\Validation\ValidationException;

final class PriceInvoice
{
    public function handle(Invoice $invoice, array $lines, float $discountPct, float $vatPct): void
    {
        $subtotal = 0;
        $rows = [];
        foreach ($lines as $index => $line) {
            $total = $line['quantity'] * $line['unit_price_minor'];
            if (! is_int($total) || $total > 100000000000000) {
                throw ValidationException::withMessages(['lines' => 'Invoice amount exceeds the supported limit.']);
            }$subtotal += $total;
            $rows[] = ['description' => $line['description'], 'quantity' => $line['quantity'], 'unit_price_minor' => $line['unit_price_minor'], 'line_total_minor' => $total, 'sort_order' => $index];
        }
        $discount = (int) round($subtotal * $discountPct / 100);
        $vat = (int) round(($subtotal - $discount) * $vatPct / 100);
        $invoice->fill(['discount_pct' => $discountPct, 'vat_rate_pct' => $vatPct, 'subtotal_minor' => $subtotal, 'discount_minor' => $discount, 'vat_minor' => $vat, 'total_minor' => $subtotal - $discount + $vat])->save();
        $invoice->lines()->delete();
        $invoice->lines()->createMany($rows);
    }
}
