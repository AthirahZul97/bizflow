<?php

namespace Tests\Feature\ExpenseReceipts;

use App\Enums\ExpenseReceiptStatus;
use App\Jobs\ProcessExpenseReceipt;
use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Models\User;
use App\Ocr\Exceptions\OcrMalformedResponseException;
use App\Ocr\Exceptions\OcrPermanentException;
use App\Ocr\Exceptions\OcrTransientException;
use App\Ocr\Providers\FakeReceiptOcrProvider;
use App\Ocr\ReceiptDocument;
use App\Ocr\ReceiptExtraction;
use App\Ocr\ReceiptExtractionNormalizer;
use App\Ocr\ReceiptOcrProvider;
use App\Services\ExpenseReceiptService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use ReflectionParameter;
use RuntimeException;
use Tests\Feature\Billing\ManagesSubscriptions;
use Tests\Support\ScriptedOcrProvider;
use Tests\TestCase;

/**
 * The queued job and the receipt state machine: claiming, the fake provider, failures,
 * retries, duplicate jobs and lost entitlement.
 */
class ReceiptProcessingTest extends TestCase
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

    private function service(): ExpenseReceiptService
    {
        return app(ExpenseReceiptService::class);
    }

    public function test_the_job_carries_only_the_receipt_id_and_is_queued_after_commit(): void
    {
        $job = new ProcessExpenseReceipt(7);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldQueueAfterCommit::class, $job);
        $this->assertSame(7, $job->expenseReceiptId);
        $this->assertSame(['expenseReceiptId'], array_map(fn (ReflectionParameter $p) => $p->getName(), (new ReflectionClass(ProcessExpenseReceipt::class))->getConstructor()->getParameters()));
        $this->assertSame(3, $job->tries);
        $this->assertLessThan((int) config('queue.connections.database.retry_after'), $job->timeout);
    }

    // ---- the fake provider --------------------------------------------------------------------

    public function test_the_fake_provider_is_deterministic(): void
    {
        $provider = new FakeReceiptOcrProvider;
        $document = new ReceiptDocument($this->png(), 'image/png', hash('sha256', $this->png()));

        $first = $provider->extract($document);
        $second = $provider->extract($document);

        $this->assertEquals($first, $second);
        $this->assertSame('42.50', $first->fields['total']['value']);
        $this->assertSame('fake', $first->provider);
        $this->assertSame('FAKE-'.strtoupper(substr(hash('sha256', $this->png()), 0, 8)), $first->fields['receipt_number']['value']);
    }

    public function test_the_configured_provider_is_the_fake_one_and_none_is_disabled(): void
    {
        $this->assertInstanceOf(FakeReceiptOcrProvider::class, app(ReceiptOcrProvider::class));

        config(['ocr.driver' => 'none']);
        $this->assertSame('none', app(ReceiptOcrProvider::class)->name());

        config(['ocr.driver' => 'something-real']);
        $this->expectException(\InvalidArgumentException::class);
        app(ReceiptOcrProvider::class);
    }

    public function test_a_receipt_is_read_into_review_and_no_expense_is_created(): void
    {
        $receipt = $this->uploadFor($this->user);

        $this->runJob($receipt);

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->status);
        $this->assertSame(1, $receipt->attempts);
        $this->assertSame('fake', $receipt->provider);
        $this->assertSame('fake-1', $receipt->provider_model);
        $this->assertNotNull($receipt->extracted_at);
        $this->assertNotNull($receipt->processing_started_at);
        $this->assertNull($receipt->last_error);
        $this->assertNotNull($receipt->counted_at, 'a successfully read receipt keeps its unit of allowance');
        $this->assertSame('Fake Merchant Sdn Bhd', $receipt->field('merchant'));
        $this->assertSame('42.50', $receipt->field('total'));
        $this->assertSame('2026-01-15', $receipt->field('date'));
        $this->assertSame(0.97, $receipt->confidence('total'));
        $this->assertFalse($receipt->isManual());
        $this->assertSame(0, Expense::count(), 'OCR never creates an expense');
        $this->assertNull($receipt->expense_id);
    }

    public function test_only_the_normalized_extraction_is_stored_never_a_raw_response(): void
    {
        $provider = new ScriptedOcrProvider([new ReceiptExtraction(
            ['total' => ['value' => '10.00'], 'raw_text' => ['value' => 'FULL OCR TEXT 4111111111111111'], 'merchant' => ['value' => 'Shop']],
            'scripted',
            'm',
            ['A note from the provider'],
        )]);
        $receipt = $this->uploadFor($this->user);

        $this->runJob($receipt, $provider);

        $stored = json_encode($receipt->refresh()->extraction);
        $this->assertStringNotContainsString('FULL OCR TEXT', $stored);
        $this->assertStringNotContainsString('4111', $stored);
        $this->assertSame(['fields', 'warnings', 'manual'], array_keys($receipt->extraction));
        $this->assertSame(['merchant', 'receipt_number', 'date', 'subtotal', 'tax', 'total', 'currency', 'payment_method', 'category', 'description'], array_keys($receipt->extraction['fields']));
    }

    public function test_the_provider_gets_the_verified_file_and_never_the_client_filename(): void
    {
        $provider = new ScriptedOcrProvider([ScriptedOcrProvider::extraction()]);
        $receipt = $this->uploadFor($this->user, $this->upload('very-private-name.png', $this->png()));

        $this->runJob($receipt, $provider);

        $this->assertSame(1, $provider->calls());
        $document = $provider->documents[0];
        $this->assertSame($this->png(), $document->contents);
        $this->assertSame('image/png', $document->mimeType);
        $this->assertSame(hash('sha256', $this->png()), $document->sha256);
        $this->assertFalse(property_exists($document, 'filename'));
    }

    public function test_a_provider_result_without_a_usable_total_is_unreadable_but_keeps_the_warnings(): void
    {
        $provider = new ScriptedOcrProvider([new ReceiptExtraction(['merchant' => ['value' => 'Shop'], 'total' => ['value' => 'illegible']], 'scripted')]);
        $receipt = $this->uploadFor($this->user);

        $this->runJob($receipt, $provider);

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Unreadable, $receipt->status);
        $this->assertSame('total_invalid', $receipt->warnings()[0]['code']);
        $this->assertNotNull($receipt->counted_at, 'the provider ran, so the unit stays used');
        $this->assertNull($receipt->field('total'));
    }

    public function test_missing_fields_become_warnings_not_guesses(): void
    {
        $provider = new ScriptedOcrProvider([new ReceiptExtraction(['total' => ['value' => '9.90']], 'scripted')]);
        $receipt = $this->uploadFor($this->user);

        $this->runJob($receipt, $provider);

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->status);
        $this->assertNull($receipt->field('date'));
        $this->assertNull($receipt->field('merchant'));
        $this->assertContains('date_missing', array_column($receipt->warnings(), 'code'));
    }

    public function test_low_confidence_is_flagged(): void
    {
        $provider = new ScriptedOcrProvider([new ReceiptExtraction([
            'total' => ['value' => '9.90', 'confidence' => 0.3],
            'date' => ['value' => '2026-09-01', 'confidence' => 0.9],
        ], 'scripted')]);
        $receipt = $this->uploadFor($this->user);

        $this->runJob($receipt, $provider);

        $warnings = $receipt->refresh()->warnings();
        $this->assertContains('low_confidence', array_column($warnings, 'code'));
        $this->assertSame('total', collect($warnings)->firstWhere('code', 'low_confidence')['field']);
    }

    // ---- failures -----------------------------------------------------------------------------

    public function test_a_permanent_provider_failure_fails_the_receipt_and_releases_its_allowance(): void
    {
        $receipt = $this->uploadFor($this->user);

        $this->runJob($receipt, new ScriptedOcrProvider([new OcrPermanentException('The provider rejected this file.')]));

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Failed, $receipt->status);
        $this->assertSame('The provider rejected this file.', $receipt->last_error);
        $this->assertNotNull($receipt->failed_at);
        $this->assertNull($receipt->counted_at);
        $this->assertSame(1, $receipt->attempts);
    }

    public function test_a_malformed_provider_response_fails_the_receipt_without_a_retry(): void
    {
        $provider = new ScriptedOcrProvider([new OcrMalformedResponseException('The OCR service returned an unusable answer.')]);
        $receipt = $this->uploadFor($this->user);

        $this->runJob($receipt, $provider);

        $this->assertSame(ExpenseReceiptStatus::Failed, $receipt->refresh()->status);
        $this->assertSame(1, $provider->calls());
    }

    public function test_a_temporary_failure_goes_back_to_the_queue_and_the_job_rethrows(): void
    {
        $provider = new ScriptedOcrProvider([new OcrTransientException('The OCR service timed out.'), ScriptedOcrProvider::extraction()]);
        $receipt = $this->uploadFor($this->user);

        try {
            $this->runJob($receipt, $provider);
            $this->fail('the job should rethrow so the queue retries it');
        } catch (OcrTransientException) {
            // expected
        }

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Queued, $receipt->status);
        $this->assertSame('The OCR service timed out.', $receipt->last_error);
        $this->assertNotNull($receipt->counted_at);
        $this->assertSame(1, $receipt->attempts);

        $this->runJob($receipt, $provider);

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->status);
        $this->assertSame(2, $receipt->attempts);
        $this->assertNull($receipt->last_error);
    }

    public function test_when_the_attempts_run_out_the_queue_fails_the_receipt(): void
    {
        $receipt = $this->uploadFor($this->user);
        $provider = new ScriptedOcrProvider([new OcrTransientException('The OCR service timed out.')]);

        try {
            $this->runJob($receipt, $provider);
        } catch (OcrTransientException $e) {
            (new ProcessExpenseReceipt($receipt->getKey()))->failed($e);
        }

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Failed, $receipt->status);
        $this->assertNull($receipt->counted_at);
    }

    public function test_an_unexpected_error_is_retried_and_its_message_is_never_stored(): void
    {
        $provider = new ScriptedOcrProvider([new RuntimeException('secret-api-key-123 and the receipt text')]);
        $receipt = $this->uploadFor($this->user);

        try {
            $this->runJob($receipt, $provider);
        } catch (RuntimeException) {
            // rethrown for the queue
        }

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Queued, $receipt->status);
        $this->assertStringNotContainsString('secret', (string) $receipt->last_error);
        $this->assertStringNotContainsString('receipt text', (string) $receipt->last_error);

        (new ProcessExpenseReceipt($receipt->getKey()))->failed(new RuntimeException('secret-api-key-123'));
        $this->assertStringNotContainsString('secret', (string) $receipt->refresh()->last_error);
    }

    public function test_the_job_logs_nothing_about_the_receipt(): void
    {
        Log::spy();
        $receipt = $this->uploadFor($this->user);

        $this->runJob($receipt);
        $this->runJob($receipt, new ScriptedOcrProvider([new OcrPermanentException('x')]));

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
    }

    public function test_a_missing_file_fails_the_receipt_for_good(): void
    {
        $receipt = $this->uploadFor($this->user);
        Storage::disk(ExpenseReceipt::DISK)->delete($receipt->storage_path);
        $provider = new ScriptedOcrProvider([ScriptedOcrProvider::extraction()]);

        $this->runJob($receipt, $provider);

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Failed, $receipt->status);
        $this->assertSame(0, $provider->calls());
    }

    public function test_a_file_that_changed_since_upload_is_never_sent_to_the_provider(): void
    {
        $receipt = $this->uploadFor($this->user);
        Storage::disk(ExpenseReceipt::DISK)->put($receipt->storage_path, 'tampered');
        $provider = new ScriptedOcrProvider([ScriptedOcrProvider::extraction()]);

        $this->runJob($receipt, $provider);

        $this->assertSame(ExpenseReceiptStatus::Failed, $receipt->refresh()->status);
        $this->assertSame(0, $provider->calls());
    }

    // ---- duplicate and concurrent workers ----------------------------------------------------

    public function test_running_the_job_again_for_a_finished_receipt_reads_nothing(): void
    {
        $provider = new ScriptedOcrProvider([ScriptedOcrProvider::extraction()]);
        $receipt = $this->uploadFor($this->user);

        $this->runJob($receipt, $provider);
        $this->runJob($receipt, $provider);
        $this->runJob($receipt, $provider);

        $this->assertSame(1, $provider->calls());
        $this->assertSame(1, $receipt->refresh()->attempts);
        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->status);
    }

    public function test_a_second_worker_cannot_claim_a_receipt_that_is_being_processed(): void
    {
        $receipt = $this->uploadFor($this->user);

        $first = $this->service()->claim($receipt->getKey());
        $second = $this->service()->claim($receipt->getKey());

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, $receipt->refresh()->attempts);
        $this->assertSame(ExpenseReceiptStatus::Processing, $receipt->status);
    }

    public function test_a_claim_loses_to_any_status_change_made_since_it_was_read(): void
    {
        $receipt = $this->uploadFor($this->user);
        $claimed = $this->service()->claim($receipt->getKey());

        // The conditional update is what guards the row: a stale copy cannot be extracted over a discard.
        ExpenseReceipt::query()->whereKey($receipt->getKey())->update(['status' => ExpenseReceiptStatus::Discarded->value]);
        $this->service()->markExtracted($claimed, (new ReceiptExtractionNormalizer)->normalize(ScriptedOcrProvider::extraction()));

        $this->assertSame(ExpenseReceiptStatus::Discarded, $receipt->refresh()->status);
        $this->assertNull($receipt->extraction);
    }

    public function test_a_receipt_discarded_while_it_was_being_read_stays_discarded(): void
    {
        $receipt = $this->uploadFor($this->user);
        $provider = new ScriptedOcrProvider([function () use ($receipt) {
            DB::table('expense_receipts')->where('id', $receipt->getKey())->update(['status' => 'discarded']);

            return ScriptedOcrProvider::extraction();
        }]);

        $this->runJob($receipt, $provider);

        $this->assertSame(ExpenseReceiptStatus::Discarded, $receipt->refresh()->status);
        $this->assertNull($receipt->extraction);
    }

    public function test_a_dead_workers_attempt_can_be_taken_over_but_a_live_one_cannot(): void
    {
        $receipt = $this->uploadFor($this->user);
        $this->service()->claim($receipt->getKey());

        $this->travel(2)->minutes();
        $this->assertNull($this->service()->claim($receipt->getKey()), 'still within the stale window');

        $this->travel(2)->minutes();
        $reclaimed = $this->service()->claim($receipt->getKey());

        $this->assertNotNull($reclaimed);
        $this->assertSame(2, $reclaimed->attempts);
        $this->assertSame(ExpenseReceiptStatus::Processing, $reclaimed->status);
    }

    public function test_a_receipt_is_never_sent_to_the_provider_more_than_the_attempt_cap(): void
    {
        config(['ocr.max_attempts' => 2]);
        $provider = new ScriptedOcrProvider([new OcrTransientException('later')]);
        $receipt = $this->uploadFor($this->user);

        foreach ([1, 2, 3] as $ignored) {
            try {
                $this->runJob($receipt, $provider);
            } catch (OcrTransientException) {
                // back in the queue
            }
        }

        $this->assertSame(2, $provider->calls());
        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Failed, $receipt->status);
        $this->assertNull($receipt->counted_at);
    }

    public function test_a_missing_receipt_row_is_ignored(): void
    {
        $provider = new ScriptedOcrProvider([ScriptedOcrProvider::extraction()]);

        (new ProcessExpenseReceipt(424242))->handle($this->service(), $provider, app(ReceiptExtractionNormalizer::class));

        $this->assertSame(0, $provider->calls());
    }

    // ---- entitlement lost before the job runs ------------------------------------------------

    public function test_a_receipt_whose_plan_lost_ocr_fails_for_good_without_calling_the_provider(): void
    {
        $receipt = $this->uploadFor($this->user);
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 0]);
        $provider = new ScriptedOcrProvider([ScriptedOcrProvider::extraction()]);

        $this->runJob($receipt, $provider);

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Failed, $receipt->status);
        $this->assertStringContainsString("doesn't include", $receipt->last_error);
        $this->assertNull($receipt->counted_at);
        $this->assertSame(0, $provider->calls());
    }

    public function test_a_receipt_in_a_business_that_went_read_only_fails_for_good(): void
    {
        $receipt = $this->uploadFor($this->user);
        $this->makeReadOnly($this->user);
        $provider = new ScriptedOcrProvider([ScriptedOcrProvider::extraction()]);

        $this->runJob($receipt, $provider);

        $this->assertSame(ExpenseReceiptStatus::Failed, $receipt->refresh()->status);
        $this->assertStringContainsString('read-only', $receipt->last_error);
        $this->assertSame(0, $provider->calls());
    }

    public function test_reading_is_not_blocked_by_the_limit_the_receipt_itself_uses(): void
    {
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 1]);
        $receipt = $this->uploadFor($this->user);

        $this->runJob($receipt);

        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->refresh()->status);
    }
}
