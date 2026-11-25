<?php

namespace App\Support;

use App\Models\RateCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class RatePricing
{
    public static function card(User $u, int $id, int $company, int $branch, int $customer): RateCard
    {
        return CompanyAccess::scope(RateCard::query(), $u)->where('company_id', $company)->where(fn (Builder $q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch))->where(fn (Builder $q) => $q->whereNull('customer_id')->orWhere('customer_id', $customer))->where('is_active', true)->findOrFail($id);
    }

    public static function total(RateCard|array $card, int $days): int
    {
        if (is_array($card)) {
            $card = new RateCard($card);
        } $daily = $card->daily_rate_minor;
        $totals = [0];
        for ($day = 1; $day <= $days; $day++) {
            $cost = $totals[$day - 1] + $daily;
            if ($day >= 7 && $card->weekly_rate_minor > 0) {
                $cost = min($cost, $totals[$day - 7] + $card->weekly_rate_minor);
            } if ($day >= 30 && $card->monthly_rate_minor > 0) {
                $cost = min($cost, $totals[$day - 30] + $card->monthly_rate_minor);
            } $totals[$day] = $cost;
        }

        return $totals[$days];
    }
}
