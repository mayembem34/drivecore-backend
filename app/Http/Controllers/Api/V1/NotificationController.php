<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    private function scope(Request $request): Builder
    {
        $user = $request->user();
        $permissions = $user->getAllPermissions()->pluck('name')->all();

        return CompanyAccess::scope(UserNotification::query(), $user)->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', $user->id))->where(function (Builder $q) use ($permissions, $user): void {
            if (! $user->is_platform_admin) {
                $q->whereNull('required_permission')->orWhereIn('required_permission', $permissions);
            }
        });
    }

    private function unread(Builder $query, Request $request): Builder
    {
        return $query->whereDoesntHave('readers', fn (Builder $q) => $q->where('users.id', $request->user()->id))->where(fn (Builder $q) => $q->whereNull('user_id')->orWhereNull('read_at'));
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['unread_only' => ['sometimes', 'boolean']]);
        $query = DirectoryQuery::apply($request, $this->scope($request), []);
        $count = $this->unread(clone $query, $request)->count();
        if ($request->boolean('unread_only')) {
            $this->unread($query, $request);
        }
        $page = $query->with(['readers' => fn ($q) => $q->where('users.id', $request->user()->id)])->orderByDesc('id')->paginate(25)->withQueryString();
        $page->through(function (UserNotification $n): array {
            $read = $n->readers->first()?->pivot->read_at ?? ($n->user_id !== null ? $n->read_at : null);

            return $n->only(['id', 'company_id', 'branch_id', 'severity', 'title', 'body', 'href', 'created_at']) + ['read_at' => $read, 'is_read' => $read !== null];
        });

        return response()->json(['data' => $page->items(), 'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()], 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => 25, 'unread_count' => $count]]);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        $record = $this->scope($request)->findOrFail($id);
        $this->mark($request, $record);

        return response()->json(['data' => ['id' => $id, 'is_read' => true]]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $query = DirectoryQuery::apply($request, $this->scope($request), []);
        foreach ($this->unread($query, $request)->lazyById(100) as $record) {
            $this->mark($request, $record);
        }

        return response()->json(['data' => ['unread_count' => 0]]);
    }

    private function mark(Request $request, UserNotification $record): void
    {
        DB::transaction(function () use ($request, $record): void {
            DB::table('notification_reads')->insertOrIgnore(['user_notification_id' => $record->id, 'user_id' => $request->user()->id, 'read_at' => now()]);
            if ($record->user_id !== null) {
                $record->update(['read_at' => $record->read_at ?? now()]);
            }
        });
    }
}
