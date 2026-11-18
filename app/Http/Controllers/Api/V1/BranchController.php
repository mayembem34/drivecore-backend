<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Branch;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return RecordResource::collection(DirectoryQuery::apply($request, CompanyAccess::scope(Branch::query(), $request->user()), ['name'], [], requiredCompany: false)->orderBy('id')->paginate(25)->withQueryString());
    }

    public function store(Request $request): RecordResource
    {
        $data = $request->validate(['company_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:255']]);
        $company = CompanyAccess::company($request->user(), $data['company_id']);
        Validator::make($data, ['name' => [Rule::unique('branches')->where('company_id', $company->id)]])->validate();
        abort_unless($request->user()->is_platform_admin || $company->pivot->branch_id === null, 403);

        return DB::transaction(function () use ($request, $data): RecordResource {
            $branch = Branch::create($data);
            RecordAudit::write($request->user(), 'branch.created', $branch);

            return new RecordResource($branch);
        });
    }
}
