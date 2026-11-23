<?php

namespace App\Support;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DirectoryQuery
{
    public static function apply(Request $request, Builder $query, array $searchColumns, array $filters = [], bool $workspace = true, bool $requiredCompany = true, array $integerFilters = [], array $sortColumns = []): Builder
    {
        $rules = ['q' => ['sometimes', 'nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000']];
        if ($workspace) {
            $rules['company_id'] = [$requiredCompany ? 'required' : 'sometimes', 'integer', 'min:1'];
            $rules['branch_id'] = ['sometimes', 'integer', 'min:1'];
        }
        foreach ($filters as $field => $values) {
            $rules[$field] = ['sometimes', Rule::in($values)];
        }
        foreach ($integerFilters as $field) {
            $rules[$field] = ['sometimes', 'integer', 'min:1'];
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
        foreach (array_merge(array_keys($filters), $integerFilters) as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['q']) && trim($data['q']) !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($data['q'])).'%';
            $query->where(function (Builder $query) use ($searchColumns, $pattern): void {
                foreach ($searchColumns as $column) {
                    if (str_contains($column, ':')) {
                        [$relation, $field] = explode(':', $column, 2);
                        $query->orWhereHas($relation, function (Builder $related) use ($field, $pattern): void {
                            $wrapped = $related->getQuery()->getGrammar()->wrap($field);
                            $related->whereRaw("LOWER(CAST({$wrapped} AS TEXT)) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
                        });
                    } else {
                        $wrapped = $query->getQuery()->getGrammar()->wrap($column);
                        $query->orWhereRaw("LOWER(CAST({$wrapped} AS TEXT)) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
                    }
                }
            });
        }

        return self::sort($request, $query, $sortColumns);
    }

    public static function sort(Request $request, Builder $query, array $columns): Builder
    {
        $data = $request->validate(['sort' => ['sometimes', Rule::in(array_keys($columns))], 'dir' => ['sometimes', Rule::in(['asc', 'desc'])]]);
        if ($request->has('dir') && ! isset($data['sort'])) {
            throw ValidationException::withMessages(['sort' => 'A sort field is required with direction.']);
        }
        if (! isset($data['sort'])) {
            return $query;
        }
        $column = $columns[$data['sort']];
        if ($column instanceof Builder) {
            $query->select($query->getModel()->getTable().'.*')->selectSub($column, '_directory_sort');
            $column = '_directory_sort';
        }
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);
        $direction = $data['dir'] ?? 'asc';

        return $query->orderByRaw($wrapped.' '.$direction.' NULLS LAST')->orderBy($query->getModel()->getTable().'.id', $direction);
    }
}
