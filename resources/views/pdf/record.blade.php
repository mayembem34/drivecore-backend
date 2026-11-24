<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#182b35}h1{font-size:20px}table{border-collapse:collapse;width:100%}th,td{padding:7px;border-bottom:1px solid #ddd;text-align:left;word-wrap:break-word}thead{display:table-header-group}tr{page-break-inside:avoid}th{width:30%}</style></head>
<body>
<h1>DriveCore · {{ $title }}</h1>
<p>Currency: {{ $currency }} · Authoritative record export</p>
<table><tbody>
@foreach($data as $key => $value)
    @if(!is_array($value) && (!is_object($value) || $value instanceof \DateTimeInterface))
    <tr><th>{{ str_replace('_', ' ', ucfirst($key)) }}</th><td>{{ $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s T') : (is_bool($value) ? ($value ? 'Yes' : 'No') : ($value ?? '—')) }}</td></tr>
    @endif
@endforeach
</tbody></table>
@if(isset($data['vehicles']))
<h2>Vehicles and snapshotted charges</h2>
<table><thead><tr><th>Vehicle</th><th>Daily rate (minor units)</th><th>Line total (minor units)</th></tr></thead><tbody>
@foreach($data['vehicles'] as $vehicle)
<tr><td>{{ $vehicle['registration'] }} · {{ $vehicle['make'] }} {{ $vehicle['model'] }}</td><td>{{ $vehicle['daily_rate_minor'] }}</td><td>{{ $vehicle['line_subtotal_minor'] }}</td></tr>
@endforeach
</tbody></table>
@endif
@if(isset($data['lines']))
<h2>Invoice lines</h2>
<table><thead><tr><th>Description</th><th>Quantity</th><th>Unit price (minor units)</th><th>Line total (minor units)</th></tr></thead><tbody>
@foreach($data['lines'] as $line)
<tr><td>{{ $line['description'] }}</td><td>{{ $line['quantity'] }}</td><td>{{ $line['unit_price_minor'] }}</td><td>{{ $line['line_total_minor'] }}</td></tr>
@endforeach
</tbody></table>
@endif
</body></html>
