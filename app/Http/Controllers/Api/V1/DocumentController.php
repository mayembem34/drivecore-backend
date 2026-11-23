<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Resources\RecordResource;
use App\Models\Document;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use App\Support\OperationalAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['entity_id' => ['sometimes', 'integer', 'min:1'], 'entity_type' => ['required_with:entity_id', Rule::in(['vehicle', 'customer', 'reservation', 'rental', 'quotation', 'invoice'])]]);

        return RecordResource::collection(DirectoryQuery::apply($request, OperationalAccess::documents($request->user()), ['original_name', 'mime_type'], ['entity_type' => ['vehicle', 'customer', 'reservation', 'rental', 'quotation', 'invoice']], integerFilters: ['entity_id'], sortColumns: ['original_name' => 'original_name', 'size' => 'size', 'expires_at' => 'expires_at'])->orderBy('id')->paginate(25)->withQueryString());
    }

    public function store(StoreDocumentRequest $request): RecordResource
    {
        $data = $request->validated();
        CompanyAccess::branch($request->user(), $data['company_id'], $data['branch_id']);
        OperationalAccess::documentTarget($request->user(), $data);
        $disk = config('filesystems.default');
        $file = $request->file('file');
        $path = $file->store('companies/'.$data['company_id'].'/documents', $disk);
        abort_unless(is_string($path), 503, 'Document storage is unavailable.');
        try {
            return DB::transaction(function () use ($request, $data, $disk, $file, $path): RecordResource {
                OperationalAccess::documentTarget($request->user(), $data, lock: true);
                $document = Document::create(['entity_type' => $data['entity_type'] ?? null, 'entity_id' => $data['entity_id'] ?? null, 'expires_at' => $data['expires_at'] ?? null, 'company_id' => $data['company_id'], 'branch_id' => $data['branch_id'], 'uploaded_by' => $request->user()->id, 'disk' => $disk, 'path' => $path, 'original_name' => basename($file->getClientOriginalName()), 'mime_type' => $file->getMimeType(), 'size' => $file->getSize()])->refresh();
                RecordAudit::write($request->user(), 'document.uploaded', $document);

                return new RecordResource($document);
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    public function show(Request $request, int $document): StreamedResponse
    {
        $record = OperationalAccess::documents($request->user())->findOrFail($document);
        Gate::authorize('view', $record);

        return Storage::disk($record->disk)->download($record->path, 'document-'.$record->id.'.'.match ($record->mime_type) {
            'application/pdf' => 'pdf','image/png' => 'png',default => 'jpg'
        }, ['Content-Type' => $record->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
