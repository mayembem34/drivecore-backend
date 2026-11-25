<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Document;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ComplianceController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $r->validate(['bucket' => ['sometimes', Rule::in(['expired', 'd7', 'd14', 'd30', 'valid'])]]);
        $q = DirectoryQuery::apply($r, CompanyAccess::scope(Document::query(), $r->user()), ['original_name'], ['category' => ['insurance', 'roadworthy', 'vehicle_licence', 'driver_licence', 'contract', 'kyc', 'other']]);
        $buckets = array_fill_keys(['expired', 'd7', 'd14', 'd30', 'valid'], 0);
        $rows = [];
        foreach ($q->orderBy('id')->lazyById(100) as $doc) {
            $days = $doc->expires_at ? now()->startOfDay()->diffInDays($doc->expires_at->startOfDay(), false) : null;
            $bucket = $days === null ? 'valid' : ($days < 0 ? 'expired' : ($days <= 7 ? 'd7' : ($days <= 14 ? 'd14' : ($days <= 30 ? 'd30' : 'valid'))));
            $buckets[$bucket]++;
            if (! $r->filled('bucket') || $r->input('bucket') === $bucket) {
                $rows[] = (new RecordResource($doc))->resolve($r) + ['bucket' => $bucket];
            }
        }

        return response()->json(['data' => ['buckets' => $buckets, 'rows' => $rows]]);
    }
}
