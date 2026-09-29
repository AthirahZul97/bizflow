<div class="card shadow-sm mb-4" data-dashboard-trend>
    <div class="card-body">
        <h2 class="h5 mb-3">Last 6 months</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Month</th>
                        <th scope="col" class="text-end">Received</th>
                        <th scope="col" class="text-end">Expenses</th>
                        <th scope="col" class="text-end">Net cash</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($trend as $row)
                        <tr data-trend-month="{{ $row['month']->format('Y-m') }}">
                            <td class="text-nowrap">{{ $row['month']->format('M Y') }}@if ($row['current']) <span class="small text-body-secondary">(to date)</span>@endif</td>
                            <td class="text-end text-nowrap">{{ \App\Support\Money::format($row['received']) }}</td>
                            <td class="text-end text-nowrap">{{ \App\Support\Money::format($row['expenses']) }}</td>
                            <td @class(['text-end', 'text-nowrap', 'text-danger' => str_starts_with($row['net'], '-')])>{{ \App\Support\Money::format($row['net']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
