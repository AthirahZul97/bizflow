<div class="card border-primary shadow-sm mb-4" data-dashboard-get-started>
    <div class="card-body">
        <h2 class="h5">Get started</h2>
        <p class="text-body-secondary">Your dashboard fills in as you record your business activity.</p>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('customers.create') }}" class="btn btn-primary">Add a customer</a>
            <a href="{{ route('invoices.create') }}" class="btn btn-outline-primary">Create an invoice</a>
            <a href="{{ route('expenses.create') }}" class="btn btn-outline-primary">Record an expense</a>
        </div>
    </div>
</div>
