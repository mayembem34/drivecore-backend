<?php

namespace App\Support;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DirectoryQuery
{
    public static function apply(Request $request, Builder $query, array $searchColumns, array $filters = [], bool $workspace = true, bool $requiredCompany = true): Builder
    {
        $rules = ['q' => ['sometimes', 'nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000']];
        if ($workspace) {
            $rules['company_id'] = [$requiredCompany ? 'required' : 'sometimes', 'integer', 'min:1'];
            $rules['branch_id'] = ['sometimes', 'integer', 'min:1'];
        }
        foreach ($filters as $field => $values) {
            $rules[$field] = ['sometimes', Rule::in($values)];
        }
        $data = $request->validate($rules);
        if ($workspace && isset($data['company_id'])) {
            CompanyAccess::company($request->user(), $data['company_id']);
            $query->where('company_id', $data['company_id']);
        }
        if ($workspace && isset($data['branch_id'])) {
            if (! isset($data['company_id'])) {
                abort(422, 'company_id is required with branch_id.');
            }
            CompanyAccess::branch($request->user(), $data['company_id'], $data['branch_id']);
            $query->where($query->getModel() instanceof Branch ? 'id' : 'branch_id', $data['branch_id']);
        }
        foreach ($filters as $field => $values) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['q']) && trim($data['q']) !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($data['q'])).'%';
            $query->where(function (Builder $query) use ($searchColumns, $pattern): void {
                foreach ($searchColumns as $column) {
                    $wrapped = $query->getQuery()->getGrammar()->wrap($column);
                    $query->orWhereRaw("LOWER({$wrapped}) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
                }
            });
        }

        return $query;
    }
}
