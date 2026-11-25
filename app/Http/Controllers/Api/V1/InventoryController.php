<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\MoveStock;
use App\Actions\PostJournal;
use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function parts(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['low_stock_only' => ['sometimes', 'boolean']]);
        $stock = '(SELECT COALESCE(SUM(quantity),0) FROM stock_movements WHERE stock_movements.part_id=parts.id)';
        $query = CompanyAccess::scope(Part::query(), $request->user())->select('parts.*')->selectRaw($stock.' AS movements_sum_quantity')->selectRaw($stock.' AS on_hand');
        if ($data['low_stock_only'] ?? false) {
            $query->whereRaw($stock.' <= min_stock');
        }

        return RecordResource::collection(DirectoryQuery::apply($request, $query, ['sku', 'name'], sortColumns: array_combine(['id', 'sku', 'name', 'unit_cost_minor', 'on_hand', 'min_stock'], ['id', 'sku', 'name', 'unit_cost_minor', 'on_hand', 'min_stock']))->orderBy('id')->paginate(25)->withQueryString());
    }

    public function savePart(Request $request, ?int $part = null): RecordResource
    {
        $record = $part === null ? new Part : CompanyAccess::record($request->user(), Part::class, $part);
        if ($part !== null) {
            Gate::authorize('update', $record);
        }
        $company = $part === null ? $request->input('company_id') : $record->company_id;
        $branch = $part === null ? $request->input('branch_id') : $record->branch_id;
        $data = $request->validate(['company_id' => [$part === null ? 'required' : 'prohibited', 'integer', 'min:1'], 'branch_id' => [$part === null ? 'required' : 'prohibited', 'integer', 'min:1'], 'location' => ['sometimes', 'nullable', 'string', 'max:120'], 'sku' => [$part === null ? 'required' : 'sometimes', 'string', 'max:64', Rule::unique('parts')->where('company_id', $company)->where('branch_id', $branch)->ignore($part)], 'name' => [$part === null ? 'required' : 'sometimes', 'string', 'max:255'], 'unit_cost_minor' => ['sometimes', 'integer', 'min:0', 'max:1000000000'], 'min_stock' => ['sometimes', 'integer', 'min:0', 'max:1000000000']]);
        CompanyAccess::branch($request->user(), (int) $company, (int) $branch);
        $record->fill($data)->save();
        RecordAudit::write($request->user(), $part === null ? 'part.created' : 'part.updated', $record);

        return new RecordResource($record->refresh()->loadSum('movements', 'quantity'));
    }

    public function adjust(Request $request, int $part, MoveStock $move, PostJournal $journal): RecordResource
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'], 'reason' => ['required', 'string', 'max:255']]);
        CompanyAccess::record($request->user(), Part::class, $part);

        return DB::transaction(function () use ($request, $part, $data, $move, $journal): RecordResource {
            $record = Part::lockForUpdate()->findOrFail($part);
            Gate::authorize('update', $record);
            $movement = $move->handle($request->user(), $record, (int) $data['quantity'], $record->unit_cost_minor, $data['reason']);
            $cost = abs($movement->quantity) * $record->unit_cost_minor;
            $journal->handle($request->user(), $record->company_id, $record->branch_id, 'stock_adjustment', $movement->id, $movement->quantity > 0 ? [['1200', $cost, 0], ['5000', 0, $cost]] : [['5000', $cost, 0], ['1200', 0, $cost]], 'STOCK-'.$movement->id, $data['reason']);
            RecordAudit::write($request->user(), 'stock.adjusted', $movement);

            return new RecordResource($record->refresh()->loadSum('movements', 'quantity'));
        }, 3);
    }

    public function movements(Request $request): AnonymousResourceCollection
    {
        $sort = ['id' => 'id', 'created_at' => 'created_at', 'quantity' => 'quantity', 'unit_cost_minor' => 'unit_cost_minor', 'part_name' => Part::select('name')->whereColumn('parts.id', 'stock_movements.part_id')->limit(1)];

        return RecordResource::collection(DirectoryQuery::apply($request, CompanyAccess::scope(StockMovement::query(), $request->user()), ['reason', 'part:name'], integerFilters: ['part_id', 'work_order_id', 'purchase_order_id'], sortColumns: $sort)->with('part')->orderByDesc('id')->paginate(25)->withQueryString());
    }

    public function suppliers(Request $request): AnonymousResourceCollection
    {
        $request->validate(['branch_id' => ['prohibited']]);

        return RecordResource::collection(DirectoryQuery::apply($request, CompanyAccess::scope(Supplier::query(), $request->user()), ['name', 'email', 'phone'], ['is_active' => [0, 1]], sortColumns: ['id' => 'id', 'name' => 'name', 'email' => 'email', 'is_active' => 'is_active'])->orderBy('id')->paginate(25)->withQueryString());
    }

    public function saveSupplier(Request $request, ?int $supplier = null): RecordResource
    {
        $record = $supplier === null ? new Supplier : CompanyAccess::record($request->user(), Supplier::class, $supplier);
        if ($supplier !== null) {
            Gate::authorize('update', $record);
        }
        $data = $request->validate(['company_id' => [$supplier === null ? 'required' : 'prohibited', 'integer', 'min:1'], 'name' => [$supplier === null ? 'required' : 'sometimes', 'string', 'max:255'], 'email' => ['sometimes', 'nullable', 'email', 'max:255'], 'phone' => ['sometimes', 'nullable', 'string', 'max:50'], 'is_active' => ['sometimes', 'boolean']]);
        CompanyAccess::company($request->user(), $supplier === null ? (int) $data['company_id'] : $record->company_id);
        $record->fill($data)->save();
        RecordAudit::write($request->user(), $supplier === null ? 'supplier.created' : 'supplier.updated', $record);

        return new RecordResource($record->refresh());
    }
}
