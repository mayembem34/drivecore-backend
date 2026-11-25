<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\InvoiceController;
use App\Models\Customer;
use App\Models\Invoice;

final class CreditControl
{
    public static function outstanding(Customer $customer): int
    {
        return max(0, (int) Invoice::where('customer_id', $customer->id)->selectRaw('COALESCE(SUM('.InvoiceController::OPEN.'),0) AS aggregate')->value('aggregate'));
    }

    public static function assertCanBook(int $customerId): void
    {
        $customer = Customer::lockForUpdate()->findOrFail($customerId);
        abort_if($customer->is_on_credit_hold || ($customer->credit_limit_minor > 0 && self::outstanding($customer) > $customer->credit_limit_minor), 409, 'Customer is on credit hold or exceeds the credit limit.');
    }
}
