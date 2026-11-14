<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCustomerRequest;
use App\Http\Resources\RecordResource;
use App\Models\Customer;
use App\Support\CompanyAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return RecordResource::collection(CompanyAccess::scope(Customer::query(), $request->user())->orderBy('id')->paginate(25));
    }

    public function show(Request $request, int $customer): RecordResource
    {
        $record = CompanyAccess::record($request->user(), Customer::class, $customer);
        Gate::authorize('view', $record);

        return new RecordResource($record);
    }

    public function store(SaveCustomerRequest $request): RecordResource
    {
        $data = $request->validated();
        CompanyAccess::branch($request->user(), $data['company_id'], $data['branch_id']);

        return DB::transaction(function () use ($request, $data): RecordResource {
            $record = Customer::create($data);
            RecordAudit::write($request->user(), 'customer.created', $record);

            return new RecordResource($record);
        });
    }

    public function update(SaveCustomerRequest $request, int $customer): RecordResource
    {
        return DB::transaction(function () use ($request, $customer): RecordResource {
            $record = CompanyAccess::scope(Customer::query(), $request->user())->lockForUpdate()->findOrFail($customer);
            Gate::authorize('update', $record);
            $data = $request->validated();
            $record->update($data);
            RecordAudit::write($request->user(), 'customer.updated', $record);

            return new RecordResource($record);
        });
    }
}
