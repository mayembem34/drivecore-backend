<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InvoiceReturnedRental
{
    public function handle(Rental $rental, array $data, User $user): Invoice
    {
        $reservation = $rental->reservation;
        $vehicle = $rental->vehicle;
        $link = DB::table('quotation_vehicles')->where('reservation_id', $reservation->id)->first();
        $quote = $link ? Quotation::findOrFail($link->quotation_id) : null;
        $rate = $link ? (int) $link->daily_rate_minor : (int) $vehicle->daily_rate_minor;
        $days = max(1, (int) ceil($reservation->starts_at->diffInSeconds($reservation->ends_at) / 86400));
        $lines = [['description' => 'Rental charge', 'quantity' => $days, 'unit_price_minor' => $rate]];
        if (($data['extra_charge_minor'] ?? 0) > 0) {
            $lines[] = ['description' => $data['extra_charge_description'], 'quantity' => 1, 'unit_price_minor' => $data['extra_charge_minor']];
        }
        $invoice = new Invoice(['company_id' => $rental->company_id, 'branch_id' => $rental->branch_id, 'customer_id' => $reservation->customer_id, 'rental_id' => $rental->id, 'created_by' => $user->id, 'invoice_number' => 'INV-'.Str::ulid(), 'status' => 'draft', 'due_at' => now()->addDays(14)]);
        (new PriceInvoice)->handle($invoice, $lines, $quote?->discount_pct ?? 0, $quote?->vat_rate_pct ?? Company::findOrFail($rental->company_id)->vat_rate_pct);
        RecordAudit::write($user, 'invoice.created', $invoice);

        return $invoice;
    }
}
