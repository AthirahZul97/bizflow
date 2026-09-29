<div class="card shadow-sm h-100" data-dashboard-status>
    <div class="card-body">
        <h2 class="h5 mb-3">Invoices by status <span class="small text-body-secondary fw-normal">&middot; all time</span></h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Count</th>
                        <th scope="col" class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($statusRows as $row)
                        <tr data-status-row="{{ $row['filter'] }}">
                            <td>
                                <a href="{{ route('invoices.index', ['status' => $row['filter']]) }}"
                                   @class(['text-danger' => $row['filter'] === 'overdue' && $row['count'] > 0])>{{ $row['label'] }}</a>
                            </td>
                            <td class="text-end">{{ $row['count'] }}</td>
                            <td @class(['text-end', 'text-nowrap', 'text-body-secondary' => $row['filter'] === 'cancelled'])>{{ \App\Support\Money::format($row['amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
