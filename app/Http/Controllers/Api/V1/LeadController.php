<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    private const STAGES = ['new', 'contacted', 'qualified', 'proposal_sent', 'won', 'lost'];

    public function index(Request $r): AnonymousResourceCollection
    {
        return RecordResource::collection(DirectoryQuery::apply($r, CompanyAccess::scope(Lead::query(), $r->user()), ['contact_name', 'company_name', 'email', 'phone'], ['stage' => self::STAGES], integerFilters: ['assigned_to_user_id'])->orderByDesc('id')->paginate(25)->withQueryString());
    }

    public function save(Request $r, ?int $id = null): RecordResource
    {
        $required = $id ? 'sometimes' : 'required';
        $d = $r->validate(['company_id' => [$id ? 'prohibited' : 'required', 'integer', 'min:1'], 'branch_id' => [$id ? 'prohibited' : 'required', 'integer', 'min:1'], 'contact_name' => [$required, 'string', 'max:120'], 'company_name' => ['nullable', 'string', 'max:140'], 'customer_type' => ['sometimes', Rule::in(['individual', 'corporate'])], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'], 'source' => ['sometimes', Rule::in(['website', 'referral', 'corporate_outreach', 'walk_in', 'phone', 'other'])], 'stage' => ['sometimes', Rule::in(self::STAGES)], 'vehicle_requirements' => ['nullable', 'string', 'max:10000'], 'expected_start_date' => ['nullable', 'date'], 'expected_days' => ['nullable', 'integer', 'between:1,3650'], 'estimated_value_minor' => ['nullable', 'integer', 'min:0', 'max:1000000000000'], 'assigned_to_user_id' => ['nullable', 'integer', 'min:1'], 'next_follow_up_at' => ['nullable', 'date'], 'lost_reason' => ['nullable', 'required_if:stage,lost', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:10000']]);
        $existing = $id ? CompanyAccess::record($r->user(), Lead::class, $id) : null;
        $company = $existing?->company_id ?? $d['company_id'];
        $branch = $existing?->branch_id ?? $d['branch_id'];
        CompanyAccess::branch($r->user(), $company, $branch);

        return DB::transaction(function () use ($r, $id, $d, $company, $branch): RecordResource {
            if (isset($d['assigned_to_user_id'])) {
                User::where('is_active', true)->whereHas('companies', fn ($q) => $q->where('companies.id', $company)->where(fn ($q) => $q->whereNull('company_user.branch_id')->orWhere('company_user.branch_id', $branch)))->findOrFail($d['assigned_to_user_id']);
            } $record = $id ? Lead::lockForUpdate()->findOrFail($id) : new Lead;
            if ($id && in_array($record->stage, ['won', 'lost'], true) && isset($d['stage']) && $d['stage'] !== $record->stage) {
                abort(409, 'A closed lead cannot return to the pipeline.');
            } $record->fill($d)->save();
            RecordAudit::write($r->user(), 'lead.saved', $record);

            return new RecordResource($record->refresh());
        }, 3);
    }

    public function convert(Request $r, int $id): RecordResource
    {
        $visible = CompanyAccess::record($r->user(), Lead::class, $id);

        return DB::transaction(function () use ($r, $visible): RecordResource {
            $lead = Lead::lockForUpdate()->findOrFail($visible->id);
            abort_if($lead->quotation_id !== null || in_array($lead->stage, ['lost', 'won'], true), 409);
            $customer = $lead->email ? Customer::where('company_id', $lead->company_id)->where('branch_id', $lead->branch_id)->whereRaw('LOWER(email)=?', [mb_strtolower($lead->email)])->first() : null;
            $customer ??= Customer::create(['company_id' => $lead->company_id, 'branch_id' => $lead->branch_id, 'name' => $lead->customer_type === 'corporate' ? ($lead->company_name ?? $lead->contact_name) : $lead->contact_name, 'type' => $lead->customer_type, 'email' => $lead->email, 'phone' => $lead->phone]);
            $copy = $r->duplicate();
            $copy->setUserResolver(fn () => $r->user());
            $copy->merge(['company_id' => $lead->company_id, 'branch_id' => $lead->branch_id, 'customer_id' => $customer->id]);
            $quote = (new QuotationController)->store($copy);
            $lead->update(['stage' => 'proposal_sent', 'customer_id' => $customer->id, 'quotation_id' => $quote->resource->id]);
            RecordAudit::write($r->user(), 'lead.converted', $lead);

            return new RecordResource($lead);
        }, 3);
    }
}
