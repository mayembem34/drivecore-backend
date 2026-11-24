<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Rental;
use App\Models\Reservation;
use App\Support\CompanyAccess;
use App\Support\OperationalAccess;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class RecordPdfController extends Controller
{
    public function __invoke(Request $request, string $domain, int $id): Response
    {
        $model = match ($domain) {
            'invoices' => Invoice::class, 'quotations' => Quotation::class,'reservations' => Reservation::class,'rentals' => Rental::class
        };
        $record = $domain === 'rentals' ? OperationalAccess::rentals($request->user())->findOrFail($id) : CompanyAccess::record($request->user(), $model, $id);
        Gate::authorize('view', $record);
        $record->load(match ($domain) {
            'invoices' => ['customer', 'lines', 'payments', 'refunds'], 'quotations' => ['customer', 'vehicles', 'decider'],'reservations' => ['customer', 'vehicle', 'rental'],'rentals' => ['reservation.customer', 'vehicle', 'driver', 'invoice']
        });
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('pdf.record', ['title' => ucfirst($domain).' #'.$record->id, 'data' => (new RecordResource($record))->resolve($request), 'currency' => CompanyAccess::company($request->user(), $record->company_id)->currency])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$domain.'-'.$id.'.pdf"', 'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff']);
    }
}
