<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MessageController extends Controller
{
    private function scope(Request $request): Builder
    {
        $query = CompanyAccess::scope(Message::query(), $request->user());
        if ($request->user()->hasRole('driver') && ! $request->user()->is_platform_admin) {
            abort_if(in_array($request->input('channel'), ['customers', 'support'], true), 403);
            $query->whereIn('channel', ['drivers', 'dispatch']);
        }

        return $query;
    }

    public function index(Request $request): JsonResponse
    {
        $query = DirectoryQuery::apply($request, $this->scope($request), [], ['channel' => ['dispatch', 'drivers', 'customers', 'support']]);
        $unread = (clone $query)->whereDoesntHave('readers', fn (Builder $q) => $q->where('users.id', $request->user()->id))->count();
        $page = $query->with('sender:id,name')->withExists(['readers as is_read' => fn (Builder $q) => $q->where('users.id', $request->user()->id)])->orderByDesc('id')->paginate(25)->withQueryString();
        $page->through(fn (Message $m) => $m->only(['id', 'company_id', 'branch_id', 'sender_user_id', 'channel', 'body', 'related_entity_type', 'related_entity_id', 'created_at']) + ['sender_name' => $m->sender?->name, 'is_read' => (bool) $m->is_read]);

        return response()->json(['data' => $page->items(), 'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()], 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => 25, 'unread_count' => $unread]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1'], 'branch_id' => ['nullable', 'integer', 'min:1'], 'channel' => ['required', Rule::in(['dispatch', 'drivers', 'customers', 'support'])], 'body' => ['required', 'string', 'min:1', 'max:1000'], 'related_entity_type' => ['nullable', 'required_with:related_entity_id', Rule::in(['vehicle', 'customer', 'reservation', 'rental', 'quotation', 'invoice', 'work_order', 'purchase_order'])], 'related_entity_id' => ['nullable', 'required_with:related_entity_type', 'integer', 'min:1']]);
        $company = CompanyAccess::company($request->user(), $data['company_id']);
        if (isset($data['branch_id'])) {
            CompanyAccess::branch($request->user(), $data['company_id'], $data['branch_id']);
        } else {
            abort_if(! $request->user()->is_platform_admin && $company->pivot->branch_id !== null, 403);
        }
        $this->scope($request);
        if (isset($data['related_entity_type'])) {
            abort_unless(isset($data['branch_id']), 422, 'A branch is required for a related entity.');
            $model = ApprovalController::TARGETS[$data['related_entity_type']];
            $target = CompanyAccess::record($request->user(), $model, $data['related_entity_id']);
            abort_unless($target->company_id === $data['company_id'] && $target->branch_id === $data['branch_id'], 404);
            Gate::authorize('view', $target);
        }

        return DB::transaction(function () use ($request, $data): JsonResponse {
            $record = Message::create($data + ['sender_user_id' => $request->user()->id]);
            RecordAudit::write($request->user(), 'message.sent', $record);
            $record->load('sender:id,name');

            return response()->json(['data' => $record->only($record->getFillable()) + ['id' => $record->id, 'sender_name' => $record->sender->name, 'is_read' => false, 'created_at' => $record->created_at]], 201);
        });
    }

    public function read(Request $request, int $id): JsonResponse
    {
        $record = $this->scope($request)->findOrFail($id);
        DB::table('message_reads')->insertOrIgnore(['message_id' => $record->id, 'user_id' => $request->user()->id, 'read_at' => now()]);

        return response()->json(['data' => ['id' => $record->id, 'is_read' => true]]);
    }
}
