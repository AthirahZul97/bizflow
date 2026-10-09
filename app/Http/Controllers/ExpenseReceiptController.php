<?php

namespace App\Http\Controllers;

use App\Billing\EntitlementService;
use App\Enums\Entitlement;
use App\Enums\ExpenseReceiptStatus;
use App\Http\Requests\ConfirmExpenseReceiptRequest;
use App\Http\Requests\StoreExpenseReceiptRequest;
use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Services\ExpenseReceiptService;
use App\Support\CurrentBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Receipt upload, status, review and confirmation. Everything is authorized through
 * ExpenseReceiptPolicy as middleware, so ownership (404 for another business) is checked before
 * any request is validated. The business always comes from CurrentBusiness and the writes from
 * ExpenseReceiptService; OCR never creates an expense, only confirm() does, from what the user
 * submitted.
 */
class ExpenseReceiptController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly CurrentBusiness $currentBusiness,
        private readonly ExpenseReceiptService $receipts,
        private readonly EntitlementService $entitlements,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.ExpenseReceipt::class, only: ['index']),
            new Middleware('can:create,'.ExpenseReceipt::class, only: ['create', 'store']),
            new Middleware('can:view,expense_receipt', only: ['show']),
            new Middleware('can:update,expense_receipt', only: ['confirm', 'manual']),
            new Middleware('can:retry,expense_receipt', only: ['retry']),
            new Middleware('can:delete,expense_receipt', only: ['delete', 'destroy']),
        ];
    }

    /**
     * The current business's receipts, newest first, with the monthly allowance.
     */
    public function index(): View
    {
        $business = $this->currentBusiness->get();
        $receipts = $business->expenseReceipts()->latest('id')->paginate(15);
        $entitlements = $this->entitlements->for($business);

        return view('expense-receipts.index', [
            'receipts' => $receipts,
            'enabled' => $this->receipts->isEnabled(),
            'fake' => $this->receipts->usesFakeProvider(),
            'limit' => $entitlements->limit(Entitlement::ReceiptOcr),
            'used' => $entitlements->used(Entitlement::ReceiptOcr),
        ]);
    }

    public function create(): View|RedirectResponse
    {
        if (! $this->receipts->isEnabled()) {
            return redirect()->route('expense-receipts.index')->with('error', 'Receipt scanning is not available.');
        }

        return view('expense-receipts.create', [
            'fake' => $this->receipts->usesFakeProvider(),
            'maxMb' => round((int) config('ocr.max_upload_kb') / 1024, 1),
        ]);
    }

    /**
     * Store the file privately and queue the OCR. The receipt is never turned into an expense here.
     */
    public function store(StoreExpenseReceiptRequest $request): RedirectResponse
    {
        $receipt = $this->receipts->upload(
            $this->currentBusiness->get(),
            $request->user(),
            $request->file('receipt'),
        );

        return redirect()->route('expense-receipts.show', $receipt)
            ->with('status', 'Receipt uploaded. We are reading it now.');
    }

    /**
     * Show the receipt in whatever state it is in: reading, ready to review, failed, or done.
     */
    public function show(ExpenseReceipt $expenseReceipt): View
    {
        $business = $this->currentBusiness->get();
        $data = [
            'receipt' => $expenseReceipt,
            'fake' => $expenseReceipt->isFake() || ($expenseReceipt->status->isActive() && $this->receipts->usesFakeProvider()),
            'duplicateFile' => $this->receipts->duplicateFile($business, $expenseReceipt),
        ];

        if ($expenseReceipt->status === ExpenseReceiptStatus::Review) {
            $suggested = $expenseReceipt->prefill();
            $data += [
                'expense' => new Expense($suggested),
                'similar' => $this->receipts->similarExpense($business, $suggested),
            ];
        }

        if ($expenseReceipt->status === ExpenseReceiptStatus::Confirmed && $expenseReceipt->expense_id !== null) {
            $data['expense'] = $business->expenses()->find($expenseReceipt->expense_id);
        }

        return view('expense-receipts.show', $data);
    }

    /**
     * Create the expense from the submitted (user-edited) values. Confirming twice is harmless.
     */
    public function confirm(ConfirmExpenseReceiptRequest $request, ExpenseReceipt $expenseReceipt): RedirectResponse
    {
        [$expense, $created] = $this->receipts->confirm(
            $this->currentBusiness->get(),
            $expenseReceipt,
            $request->user(),
            $request->validated(),
        );

        return redirect()->route('expenses.show', $expense)
            ->with('status', $created ? 'Expense recorded from your receipt.' : 'This receipt was already confirmed; here is its expense.');
    }

    public function retry(ExpenseReceipt $expenseReceipt): RedirectResponse
    {
        $this->receipts->retry($this->currentBusiness->get(), $expenseReceipt);

        return redirect()->route('expense-receipts.show', $expenseReceipt)
            ->with('status', 'We are reading the receipt again.');
    }

    public function manual(ExpenseReceipt $expenseReceipt): RedirectResponse
    {
        $this->receipts->useManualEntry($this->currentBusiness->get(), $expenseReceipt);

        return redirect()->route('expense-receipts.show', $expenseReceipt);
    }

    /**
     * Ask for confirmation before discarding a receipt. This action never discards.
     */
    public function delete(ExpenseReceipt $expenseReceipt): View
    {
        return view('expense-receipts.delete', ['receipt' => $expenseReceipt]);
    }

    public function destroy(ExpenseReceipt $expenseReceipt): RedirectResponse
    {
        $this->receipts->discard($this->currentBusiness->get(), $expenseReceipt);

        return redirect()->route('expense-receipts.index')->with('status', 'Receipt discarded.');
    }
}
