<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class InsightQuery
{
    public static function workspace(Request $request, string $model): Builder
    {
        $request->validate(['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return DirectoryQuery::apply($request, CompanyAccess::scope($model::query(), $request->user()), []);
    }

    public static function period(Builder $query, Request $request, string $column): Builder
    {
        if ($request->filled('from')) {
            $query->where($column, '>=', $request->date('from')->startOfDay());
        } if ($request->filled('to')) {
            $query->where($column, '<=', $request->date('to')->endOfDay());
        }

        return $query;
    }
}
