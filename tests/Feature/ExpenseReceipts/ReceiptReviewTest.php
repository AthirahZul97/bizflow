<?php

namespace Tests\Feature\ExpenseReceipts;

use App\Enums\ExpenseReceiptStatus;
use App\Exceptions\EntitlementException;
use App\Exceptions\ExpenseReceiptException;
use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Models\User;
use App\Ocr\Exceptions\OcrPermanentException;
use App\Ocr\ReceiptExtraction;
use App\Ocr\ReceiptOcrProvider;
use App\Services\ExpenseReceiptService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Billing\ManagesSubscriptions;
use Tests\Support\ScriptedOcrProvider;
use Tests\TestCase;

/**
 * The review page and confirming: user-edited values win, exactly one expense per receipt,
 * atomicity, manual entry, duplicate warnings and the pages themselves.
 */
class ReceiptReviewTest extends TestCase
{
    use HandlesReceipts;
    use ManagesSubscriptions;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpReceipts();
        $this->user = User::factory()->create();
    }

    private function confirm(ExpenseReceipt $receipt, array $payload = [])
    {
        return $this->actingAs($this->user)->post(route('expense-receipts.confirm', $receipt), $this->confirmPayload($payload));
    }

    // ---- the review page ----------------------------------------------------------------------

    public function test_the_review_page_prefills_every_field_from_the_extraction(): void
    {
        $receipt = $this->receiptInReview($this->user);

        $response = $this->actingAs($this->user)->get(route('expense-receipts.show', $receipt))->assertOk();

        $response->assertSee('value="2026-01-15"', false)
            ->assertSee('value="42.50"', false)
            ->assertSee('value="Fake Merchant Sdn Bhd"', false)
            ->assertSee('value="Fake Merchant Sdn Bhd receipt"', false)
            ->assertSee('Receipt no: FAKE-', false)
            ->assertSee('Subtotal: 40.09', false)
            ->assertSee('SST/tax: 2.41', false)
            ->assertSee('Paid by: Cash', false)
            ->assertSee('Confirm &amp; create expense', false)
            ->assertSee('Demo OCR');
        $this->assertSame(0, Expense::count());
    }

    public function test_every_expense_field_can_be_edited_and_has_its_own_input(): void
    {
        $receipt = $this->receiptInReview($this->user);

        $html = $this->actingAs($this->user)->get(route('expense-receipts.show', $receipt))->getContent();

        foreach (['expense_date', 'category', 'description', 'amount', 'payee', 'notes'] as $field) {
            $this->assertMatchesRegularExpression('/name="'.$field.'"/', $html, $field);
            $this->assertDoesNotMatchRegularExpression('/name="'.$field.'"[^>]*(readonly|disabled)/', $html, $field);
        }
    }

    public function test_the_review_page_is_laid_out_for_a_375px_screen(): void
    {
        $receipt = $this->receiptInReview($this->user);

        $html = $this->actingAs($this->user)->get(route('expense-receipts.show', $receipt))->getContent();

        $this->assertStringContainsString('name="viewport" content="width=device-width, initial-scale=1"', $html);
        // One column below lg: the preview stacks above the form instead of beside it.
        $this->assertStringContainsString('col-lg-5', $html);
        $this->assertStringContainsString('col-lg-7', $html);
        $this->assertStringNotContainsString('col-5', $html);
        $this->assertStringNotContainsString('col-7', $html);
        $this->assertStringContainsString('img-fluid', $html);
        $this->assertStringContainsString('inputmode="decimal"', $html);
        $this->assertStringContainsString('sticky-bottom', $html);
        $this->assertStringContainsString('d-grid d-sm-flex', $html);
        $this->assertDoesNotMatchRegularExpression('/style="[^"]*(?<!max-)width:\s*\d{3,}px/', $html);
        $this->assertDoesNotMatchRegularExpression('/min-width:\s*\d{3,}px/', $html);
    }

    public function test_the_original_receipt_is_shown_through_the_private_route(): void
    {
        $receipt = $this->receiptInReview($this->user);

        $this->actingAs($this->user)->get(route('expense-receipts.show', $receipt))
            ->assertSee(route('expense-receipts.file', $receipt), false)
            ->assertSee('download=1', false);
    }

    public function test_a_pdf_receipt_offers_a_download_not_an_inline_preview(): void
    {
        $receipt = $this->uploadFor($this->user, $this->upload('r.pdf', $this->pdf()));
        $this->runJob($receipt);

        $html = $this->actingAs($this->user)->get(route('expense-receipts.show', $receipt))->getContent();

        $this->assertStringContainsString('Download PDF', $html);
        $this->assertStringNotContainsString('<img src', $html);
    }

    public function test_warnings_and_confidence_are_shown(): void
    {
        $provider = new ScriptedOcrProvider([new ReceiptExtraction([
            'total' => ['value' => '9.90', 'confidence' => 0.3],
            'merchant' => ['value' => 'Shop', 'confidence' => null],
            'date' => ['value' => '03/04/2026', 'confidence' => 0.9],
        ], 'scripted')]);
        $receipt = $this->uploadFor($this->user);
        $this->runJob($receipt, $provider);

        $this->actingAs($this->user)->get(route('expense-receipts.show', $receipt))
            ->assertSee('data-testid="ocr-warning"', false)
            ->assertSee('hard to read')
            ->assertSee('day/month')
            ->assertSee('Low')
            ->assertSee('Unverified')
            ->assertSee('Not found');
    }

    public function test_the_page_for_each_state_works(): void
    {
        $queued = $this->uploadFor($this->user);
        $failed = ExpenseReceipt::factory()->failed()->create(['business_id' => $this->businessOf($this->user)]);
        $discarded = ExpenseReceipt::factory()->status(ExpenseReceiptStatus::Discarded)->create(['business_id' => $this->businessOf($this->user)]);

        $this->actingAs($this->user)->get(route('expense-receipts.show', $queued))->assertOk()->assertSee('Reading your receipt');
        $this->actingAs($this->user)->get(route('expense-receipts.show', $failed))->assertOk()->assertSee('Retry reading')->assertSee('Enter details myself');
        $this->actingAs($this->user)->get(route('expense-receipts.show', $discarded))->assertOk()->assertSee('discarded');
    }

    public function test_the_receipt_list_shows_each_status(): void
    {
        $this->uploadFor($this->user);
        $this->receiptInReview($this->user);

        $this->actingAs($this->user)->get(route('expense-receipts.index'))
            ->assertOk()->assertSee('Queued')->assertSee('Ready to review');
    }

    // ---- confirming ---------------------------------------------------------------------------

    public function test_confirming_creates_exactly_one_expense_from_the_users_values(): void
    {
        $receipt = $this->receiptInReview($this->user);

        $response = $this->confirm($receipt, [
            'expense_date' => '2026-09-21',
            'category' => 'travel',
            'description' => 'Taxi to client',
            'amount' => '55.00',
            'payee' => 'Grab',
            'notes' => 'My own notes',
        ]);

        $expense = Expense::sole();
        $response->assertRedirect(route('expenses.show', $expense));
        // The user's edits win over every extracted value.
        $this->assertSame('2026-09-21', $expense->expense_date->toDateString());
        $this->assertSame('travel', $expense->category->value);
        $this->assertSame('Taxi to client', $expense->description);
        $this->assertSame('55.00', $expense->amount);
        $this->assertSame('Grab', $expense->payee);
        $this->assertSame('My own notes', $expense->notes);
        $this->assertSame($this->businessOf($this->user)->getKey(), $expense->business_id);
        $this->assertSame($this->user->getKey(), $expense->created_by);

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Confirmed, $receipt->status);
        $this->assertSame($expense->getKey(), $receipt->expense_id);
        $this->assertSame($this->user->getKey(), $receipt->confirmed_by);
        $this->assertNotNull($receipt->confirmed_at);
        $this->assertEqualsCanonicalizing(['expense_date', 'category', 'description', 'amount', 'payee', 'notes'], $receipt->edited_fields);
        $this->assertTrue(Storage::disk(ExpenseReceipt::DISK)->exists($receipt->storage_path), 'the file is kept with the expense');
    }

    public function test_unedited_extracted_values_are_not_recorded_as_edits(): void
    {
        $kept = $this->receiptInReview($this->user);
        $changed = $this->receiptInReview($this->user);
        $suggested = $kept->prefill();

        // "42.5" is the same amount as the suggested "42.50".
        $this->confirm($kept, ['amount' => '42.5'] + $suggested)->assertSessionHasNoErrors();
        $this->confirm($changed, ['payee' => 'Another Shop'] + $changed->prefill())->assertSessionHasNoErrors();

        $this->assertSame([], $kept->refresh()->edited_fields);
        $this->assertSame(['payee'], $changed->refresh()->edited_fields);
    }

    public function test_the_expense_page_links_back_to_its_receipt(): void
    {
        $receipt = $this->receiptInReview($this->user);
        $this->confirm($receipt);

        $this->actingAs($this->user)->get(route('expenses.show', Expense::sole()))
            ->assertOk()->assertSee(route('expense-receipts.show', $receipt), false);

        $manual = Expense::factory()->ownedBy($this->user)->create();
        $this->actingAs($this->user)->get(route('expenses.show', $manual))->assertDontSee('View scanned receipt');
    }

    public function test_confirming_twice_creates_exactly_one_expense(): void
    {
        $receipt = $this->receiptInReview($this->user);

        $first = $this->confirm($receipt);
        $second = $this->confirm($receipt, ['amount' => '999.00', 'description' => 'A double click']);

        $this->assertSame(1, Expense::count());
        $expense = Expense::sole();
        $this->assertSame('25.90', $expense->amount, 'the second submission changes nothing');
        $first->assertRedirect(route('expenses.show', $expense));
        $second->assertRedirect(route('expenses.show', $expense))->assertSessionHas('status', fn ($status) => str_contains($status, 'already confirmed'));
    }

    public function test_the_service_confirms_idempotently(): void
    {
        $receipt = $this->receiptInReview($this->user);
        $business = $this->businessOf($this->user);
        $service = app(ExpenseReceiptService::class);

        [$one, $created] = $service->confirm($business, $receipt, $this->user, $this->confirmPayload());
        [$two, $createdAgain] = $service->confirm($business, $receipt->fresh(), $this->user, $this->confirmPayload(['amount' => '1.00']));
        [$three] = $service->confirm($business, $receipt, $this->user, $this->confirmPayload());

        $this->assertTrue($created);
        $this->assertFalse($createdAgain);
        $this->assertTrue($one->is($two) && $one->is($three));
        $this->assertSame(1, Expense::count());
    }

    public function test_a_stale_copy_of_the_receipt_cannot_confirm_twice(): void
    {
        // Two requests both read the receipt as "review" before either confirmed.
        $receipt = $this->receiptInReview($this->user);
        $stale = ExpenseReceipt::find($receipt->getKey());
        $service = app(ExpenseReceiptService::class);
        $business = $this->businessOf($this->user);

        $service->confirm($business, $receipt, $this->user, $this->confirmPayload());
        $service->confirm($business, $stale, $this->user, $this->confirmPayload());

        $this->assertSame(1, Expense::count());
    }

    public function test_the_database_refuses_two_receipts_for_one_expense(): void
    {
        $business = $this->businessOf($this->user);
        $expense = Expense::factory()->create(['business_id' => $business->getKey()]);
        ExpenseReceipt::factory()->create(['business_id' => $business->getKey(), 'expense_id' => $expense->getKey()]);

        $this->expectException(QueryException::class);
        ExpenseReceipt::factory()->create(['business_id' => $business->getKey(), 'expense_id' => $expense->getKey()]);
    }

    /**
     * @return array<string, array{ExpenseReceiptStatus}>
     */
    public static function unconfirmableStates(): array
    {
        return [
            'queued' => [ExpenseReceiptStatus::Queued],
            'processing' => [ExpenseReceiptStatus::Processing],
            'failed' => [ExpenseReceiptStatus::Failed],
            'unreadable' => [ExpenseReceiptStatus::Unreadable],
            'discarded' => [ExpenseReceiptStatus::Discarded],
        ];
    }

    #[DataProvider('unconfirmableStates')]
    public function test_only_a_receipt_in_review_can_be_confirmed(ExpenseReceiptStatus $status): void
    {
        $receipt = ExpenseReceipt::factory()->status($status)->create(['business_id' => $this->businessOf($this->user)]);

        $this->confirm($receipt)->assertSessionHas('error');

        $this->assertSame(0, Expense::count());
        $this->assertSame($status, $receipt->refresh()->status);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidValues(): array
    {
        return [
            'no date' => [['expense_date' => ''], 'expense_date'],
            'future date' => [['expense_date' => '2030-01-01'], 'expense_date'],
            'date before 2000' => [['expense_date' => '1999-12-31'], 'expense_date'],
            'not a date' => [['expense_date' => 'yesterday'], 'expense_date'],
            'no amount' => [['amount' => ''], 'amount'],
            'zero amount' => [['amount' => '0'], 'amount'],
            'negative amount' => [['amount' => '-5'], 'amount'],
            'three decimals' => [['amount' => '1.234'], 'amount'],
            'not a number' => [['amount' => 'twelve'], 'amount'],
            'unknown category' => [['category' => 'yachts'], 'category'],
            'no category' => [['category' => ''], 'category'],
            'no description' => [['description' => ''], 'description'],
            'description too long' => [['description' => str_repeat('a', 256)], 'description'],
            'payee too long' => [['payee' => str_repeat('a', 256)], 'payee'],
            'notes too long' => [['notes' => str_repeat('a', 5001)], 'notes'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidValues')]
    public function test_values_are_validated_like_a_hand_entered_expense(array $override, string $field): void
    {
        $receipt = $this->receiptInReview($this->user);

        $this->confirm($receipt, $override)->assertSessionHasErrors($field);

        $this->assertSame(0, Expense::count());
        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->refresh()->status);
        $this->assertNull($receipt->expense_id);
    }

    public function test_a_forged_business_creator_or_link_in_the_confirm_request_is_ignored(): void
    {
        $other = User::factory()->create();
        $receipt = $this->receiptInReview($this->user);

        $this->confirm($receipt, [
            'business_id' => $this->businessOf($other)->getKey(),
            'created_by' => $other->getKey(),
            'expense_id' => 99,
            'status' => 'discarded',
            'id' => 12345,
        ]);

        $expense = Expense::sole();
        $this->assertSame($this->businessOf($this->user)->getKey(), $expense->business_id);
        $this->assertSame($this->user->getKey(), $expense->created_by);
        $this->assertSame($expense->getKey(), $receipt->refresh()->expense_id);
        $this->assertSame(ExpenseReceiptStatus::Confirmed, $receipt->status);
    }

    public function test_a_failure_while_confirming_leaves_no_expense_and_the_receipt_in_review(): void
    {
        $receipt = $this->receiptInReview($this->user);
        // Fires after the expense row has been inserted, inside the confirm transaction.
        Expense::created(fn () => throw new RuntimeException('disk full'));

        try {
            $this->withoutExceptionHandling()->actingAs($this->user)
                ->post(route('expense-receipts.confirm', $receipt), $this->confirmPayload());
            $this->fail('the confirm should have failed');
        } catch (RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        } finally {
            Expense::flushEventListeners();
        }

        $this->assertSame(0, Expense::count(), 'no partial expense remains');
        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->status);
        $this->assertNull($receipt->expense_id);
        $this->assertNull($receipt->confirmed_at);

        // And the user can simply try again.
        $this->confirm($receipt)->assertSessionHasNoErrors();
        $this->assertSame(1, Expense::count());
    }

    public function test_a_lost_race_on_the_status_change_rolls_the_expense_back(): void
    {
        $receipt = $this->receiptInReview($this->user);
        // After the expense is inserted, another request discards the receipt behind our back.
        Expense::created(fn () => \DB::table('expense_receipts')->where('id', $receipt->getKey())->update(['status' => 'discarded']));

        try {
            $this->expectException(ExpenseReceiptException::class);
            app(ExpenseReceiptService::class)->confirm($this->businessOf($this->user), $receipt, $this->user, $this->confirmPayload());
        } finally {
            Expense::flushEventListeners();
            $this->assertSame(0, Expense::count());
        }
    }

    public function test_confirming_needs_write_access_at_the_moment_it_runs(): void
    {
        $receipt = $this->receiptInReview($this->user);
        $this->makeReadOnly($this->user);

        $this->expectException(EntitlementException::class);
        try {
            app(ExpenseReceiptService::class)->confirm($this->businessOf($this->user), $receipt, $this->user, $this->confirmPayload());
        } finally {
            $this->assertSame(0, Expense::count());
            $this->assertSame(ExpenseReceiptStatus::Review, $receipt->refresh()->status);
        }
    }

    public function test_confirming_works_on_a_plan_that_no_longer_includes_ocr(): void
    {
        $receipt = $this->receiptInReview($this->user);
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 0]);

        $this->confirm($receipt)->assertSessionHasNoErrors();

        $this->assertSame(1, Expense::count());
    }

    public function test_deleting_the_expense_keeps_the_receipt_and_its_file(): void
    {
        $receipt = $this->receiptInReview($this->user);
        $this->confirm($receipt);
        $expense = Expense::sole();

        $this->actingAs($this->user)->delete(route('expenses.destroy', $expense))->assertRedirect(route('expenses.index'));

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Confirmed, $receipt->status);
        $this->assertNull($receipt->expense_id);
        $this->assertTrue($receipt->hasFile());
        $this->actingAs($this->user)->get(route('expense-receipts.show', $receipt))->assertOk()->assertSee('Expense deleted');
    }

    // ---- duplicate warnings (never blocks) ---------------------------------------------------

    public function test_the_same_file_uploaded_twice_warns_but_is_allowed(): void
    {
        $bytes = $this->png().'twice';
        $first = $this->uploadFor($this->user, $this->upload('a.png', $bytes));
        $second = $this->uploadFor($this->user, $this->upload('b.png', $bytes));
        $this->runJob($second);

        $this->actingAs($this->user)->get(route('expense-receipts.show', $second))
            ->assertSee('data-testid="duplicate-file-warning"', false)
            ->assertSee(route('expense-receipts.show', $first), false);
        $this->actingAs($this->user)->get(route('expense-receipts.show', $first))->assertDontSee('data-testid="duplicate-file-warning"', false);

        $this->confirm($second)->assertSessionHasNoErrors();
        $this->assertSame(1, Expense::count(), 'a duplicate is warned about, never blocked');
    }

    public function test_a_discarded_duplicate_is_not_warned_about(): void
    {
        $bytes = $this->png().'again';
        $first = $this->uploadFor($this->user, $this->upload('a.png', $bytes));
        $this->actingAs($this->user)->delete(route('expense-receipts.destroy', $first));
        $second = $this->uploadFor($this->user, $this->upload('b.png', $bytes));

        $this->actingAs($this->user)->get(route('expense-receipts.show', $second))->assertDontSee('data-testid="duplicate-file-warning"', false);
    }

    public function test_an_existing_expense_with_the_same_payee_date_and_amount_warns_but_is_allowed(): void
    {
        Expense::factory()->ownedBy($this->user)->create([
            'payee' => 'FAKE MERCHANT SDN BHD', 'expense_date' => '2026-01-15', 'amount' => '42.50',
        ]);
        $receipt = $this->receiptInReview($this->user);

        $this->actingAs($this->user)->get(route('expense-receipts.show', $receipt))
            ->assertSee('data-testid="similar-expense-warning"', false);

        $this->confirm($receipt, ['payee' => 'FAKE MERCHANT SDN BHD', 'expense_date' => '2026-01-15', 'amount' => '42.50'])->assertSessionHasNoErrors();
        $this->assertSame(2, Expense::count());
    }

    public function test_another_businesss_expense_never_triggers_the_similar_expense_warning(): void
    {
        $other = User::factory()->create();
        Expense::factory()->ownedBy($other)->create(['payee' => 'Fake Merchant Sdn Bhd', 'expense_date' => '2026-01-15', 'amount' => '42.50']);
        $receipt = $this->receiptInReview($this->user);

        $this->actingAs($this->user)->get(route('expense-receipts.show', $receipt))
            ->assertDontSee('data-testid="similar-expense-warning"', false);
    }

    // ---- manual entry -------------------------------------------------------------------------

    public function test_a_failed_receipt_can_be_filled_in_by_hand_without_any_ocr(): void
    {
        $receipt = $this->uploadFor($this->user);
        $this->runJob($receipt, new ScriptedOcrProvider([new OcrPermanentException('Rejected.')]));
        $provider = new ScriptedOcrProvider([ScriptedOcrProvider::extraction()]);
        $this->app->bind(ReceiptOcrProvider::class, fn () => $provider);

        $this->actingAs($this->user)->post(route('expense-receipts.manual', $receipt))->assertRedirect(route('expense-receipts.show', $receipt));

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->status);
        $this->assertTrue($receipt->isManual());
        $this->assertNull($receipt->counted_at, 'manual entry uses no OCR allowance');
        $this->assertSame(0, $provider->calls());

        $this->actingAs($this->user)->get(route('expense-receipts.show', $receipt))->assertOk()->assertSee('Fill in the details');

        $this->confirm($receipt)->assertSessionHasNoErrors();
        $this->assertSame(1, Expense::count());
        $this->assertSame(0, $provider->calls());
        $this->assertNull($receipt->refresh()->counted_at);
        $this->assertEqualsCanonicalizing(['expense_date', 'category', 'description', 'amount', 'payee', 'notes'], $receipt->edited_fields);
    }

    public function test_an_unreadable_receipt_can_also_be_entered_by_hand(): void
    {
        $receipt = $this->uploadFor($this->user);
        $this->runJob($receipt, new ScriptedOcrProvider([new ReceiptExtraction([], 'scripted')]));
        $this->assertSame(ExpenseReceiptStatus::Unreadable, $receipt->refresh()->status);

        $this->actingAs($this->user)->post(route('expense-receipts.manual', $receipt));

        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->refresh()->status);
        $this->assertNotNull($receipt->counted_at, 'the provider ran for this one, so its unit stays used');
    }

    /**
     * @return array<string, array{ExpenseReceiptStatus}>
     */
    public static function notManualStates(): array
    {
        return [
            'queued' => [ExpenseReceiptStatus::Queued],
            'review' => [ExpenseReceiptStatus::Review],
            'confirmed' => [ExpenseReceiptStatus::Confirmed],
            'discarded' => [ExpenseReceiptStatus::Discarded],
        ];
    }

    #[DataProvider('notManualStates')]
    public function test_manual_entry_is_only_for_failed_or_unreadable_receipts(ExpenseReceiptStatus $status): void
    {
        $receipt = ExpenseReceipt::factory()->status($status)->create(['business_id' => $this->businessOf($this->user)]);

        $this->actingAs($this->user)->post(route('expense-receipts.manual', $receipt))->assertSessionHas('error');

        $this->assertSame($status, $receipt->refresh()->status);
    }
}
