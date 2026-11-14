<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CompanyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return RecordResource::collection(($request->user()->is_platform_admin ? Company::query() : $request->user()->companies())->orderBy('companies.id')->paginate(25));
    }

    public function store(Request $request): RecordResource
    {
        abort_unless($request->user()->is_platform_admin, 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/']]);

        return DB::transaction(function () use ($request, $data): RecordResource {
            $company = Company::create($data);
            RecordAudit::write($request->user(), 'company.created', $company);

            return new RecordResource($company);
        });
    }
}
