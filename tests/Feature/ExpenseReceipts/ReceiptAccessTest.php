<?php

namespace Tests\Feature\ExpenseReceipts;

use App\Enums\ExpenseReceiptStatus;
use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Billing\ManagesSubscriptions;
use Tests\TestCase;

/**
 * Authentication, cross-business isolation, read-only access and private file serving.
 */
class ReceiptAccessTest extends TestCase
{
    use HandlesReceipts;
    use ManagesSubscriptions;
    use RefreshDatabase;

    private User $owner;

    private User $intruder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpReceipts();
        $this->owner = User::factory()->create();
        $this->intruder = User::factory()->create();
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function routes(): array
    {
        return [
            'index' => ['get', 'expense-receipts.index', false],
            'create' => ['get', 'expense-receipts.create', false],
            'store' => ['post', 'expense-receipts.store', false],
            'show' => ['get', 'expense-receipts.show', true],
            'file' => ['get', 'expense-receipts.file', true],
            'confirm' => ['post', 'expense-receipts.confirm', true],
            'retry' => ['post', 'expense-receipts.retry', true],
            'manual' => ['post', 'expense-receipts.manual', true],
            'delete confirmation' => ['get', 'expense-receipts.delete', true],
            'destroy' => ['delete', 'expense-receipts.destroy', true],
        ];
    }

    #[DataProvider('routes')]
    public function test_guests_are_redirected_to_login(string $method, string $route, bool $needsReceipt): void
    {
        $receipt = $this->uploadFor($this->owner);

        $this->{$method}($needsReceipt ? route($route, $receipt) : route($route))->assertRedirect(route('login'));

        $this->assertSame(ExpenseReceiptStatus::Queued, $receipt->refresh()->status);
        $this->assertSame(1, ExpenseReceipt::count());
    }

    #[DataProvider('routes')]
    public function test_another_business_cannot_reach_a_receipt_by_any_route(string $method, string $route, bool $needsReceipt): void
    {
        if (! $needsReceipt) {
            $this->assertTrue(true);

            return;
        }

        $receipt = $this->receiptInReview($this->owner);

        $this->actingAs($this->intruder)->{$method}(route($route, $receipt), $this->confirmPayload())->assertNotFound();

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->status);
        $this->assertNull($receipt->expense_id);
        $this->assertSame(0, Expense::count());
        $this->assertTrue(Storage::disk(ExpenseReceipt::DISK)->exists($receipt->storage_path));
    }

    public function test_the_list_only_shows_the_current_businesss_receipts(): void
    {
        $mine = $this->uploadFor($this->owner, $this->upload('mine-receipt.png', $this->uniquePng()));
        $theirs = $this->uploadFor($this->intruder, $this->upload('their-secret.png', $this->uniquePng()));

        $this->actingAs($this->owner)->get(route('expense-receipts.index'))
            ->assertOk()->assertSee('mine-receipt.png')->assertDontSee('their-secret.png');
        $this->actingAs($this->intruder)->get(route('expense-receipts.index'))
            ->assertOk()->assertSee('their-secret.png')->assertDontSee('mine-receipt.png');

        $this->assertNotSame($mine->business_id, $theirs->business_id);
    }

    public function test_a_forged_receipt_id_is_indistinguishable_from_a_missing_one(): void
    {
        $this->actingAs($this->owner)->get(route('expense-receipts.show', 999999))->assertNotFound();
        $this->actingAs($this->owner)->get(route('expense-receipts.file', 999999))->assertNotFound();
    }

    public function test_the_duplicate_file_warning_never_crosses_businesses(): void
    {
        $bytes = $this->png().'same';
        $this->uploadFor($this->owner, $this->upload('a.png', $bytes));
        $theirs = $this->uploadFor($this->intruder, $this->upload('b.png', $bytes));

        $this->actingAs($this->intruder)->get(route('expense-receipts.show', $theirs))
            ->assertOk()->assertDontSee('already uploaded');
    }

    // ---- private file serving ----------------------------------------------------------------

    public function test_the_owner_sees_an_image_inline_with_safe_headers(): void
    {
        $receipt = $this->uploadFor($this->owner, $this->upload('r.png', $this->png()));

        $response = $this->actingAs($this->owner)->get(route('expense-receipts.file', $receipt));

        $response->assertOk();
        $this->assertSame($this->png(), $response->streamedContent());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    public function test_a_pdf_is_always_a_download_and_an_image_can_be_forced_to_one(): void
    {
        $pdf = $this->uploadFor($this->owner, $this->upload('r.pdf', $this->pdf()));
        $image = $this->uploadFor($this->owner, $this->upload('r.png', $this->uniquePng()));

        $this->actingAs($this->owner)->get(route('expense-receipts.file', $pdf))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('attachment', $this->actingAs($this->owner)->get(route('expense-receipts.file', $pdf))->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('attachment', $this->actingAs($this->owner)->get(route('expense-receipts.file', ['expense_receipt' => $image, 'download' => 1]))->headers->get('Content-Disposition'));
    }

    public function test_the_download_name_is_the_cleaned_label_never_a_path(): void
    {
        $receipt = $this->uploadFor($this->owner, $this->upload('../../x/evil.png', $this->png()));

        $disposition = $this->actingAs($this->owner)->get(route('expense-receipts.file', $receipt))->headers->get('Content-Disposition');

        $this->assertStringContainsString('evil.png', $disposition);
        $this->assertStringNotContainsString('..', $disposition);
        $this->assertStringNotContainsString('/', str_replace('inline; filename=', '', $disposition));
    }

    public function test_a_deleted_file_is_a_404_not_an_error(): void
    {
        $receipt = $this->uploadFor($this->owner);
        Storage::disk(ExpenseReceipt::DISK)->delete($receipt->storage_path);

        $this->actingAs($this->owner)->get(route('expense-receipts.file', $receipt))->assertNotFound();
    }

    public function test_the_receipts_disk_is_private_with_no_public_url(): void
    {
        $config = config('filesystems.disks.receipts');

        $this->assertSame('private', $config['visibility']);
        $this->assertArrayNotHasKey('url', $config);
        $this->assertFalse($config['serve']);
        $this->assertTrue($config['throw']);
        $this->assertStringNotContainsString(str_replace('\\', '/', public_path()), str_replace('\\', '/', $config['root']));
        $this->assertSame(str_replace('\\', '/', storage_path('app/receipts')), str_replace('\\', '/', $config['root']));
    }

    public function test_a_stored_receipt_cannot_be_fetched_from_a_guessable_public_path(): void
    {
        $receipt = $this->uploadFor($this->owner);

        // The framework's /storage route (signed URLs only, for the local disk) refuses everything
        // unsigned; the receipts disk is not under it anyway.
        foreach (['/storage/'.$receipt->storage_path, '/storage/receipts/'.$receipt->storage_path, '/receipts/'.$receipt->storage_path] as $url) {
            $this->assertContains($this->actingAs($this->owner)->get($url)->status(), [403, 404], $url);
            $this->assertContains($this->get($url)->status(), [302, 403, 404], $url);
        }
        $this->assertFileDoesNotExist(public_path($receipt->storage_path));
    }

    public function test_the_stored_filename_is_not_the_original_name(): void
    {
        $receipt = $this->uploadFor($this->owner, $this->upload('invoice-from-secret-supplier.png', $this->png()));

        $this->assertStringNotContainsString('secret', $receipt->storage_path);
        $this->assertStringNotContainsString('invoice', $receipt->storage_path);
    }

    // ---- read-only subscriptions -------------------------------------------------------------

    public function test_a_read_only_business_can_still_view_receipts_and_files(): void
    {
        $receipt = $this->receiptInReview($this->owner);
        $this->makeReadOnly($this->owner);

        $this->actingAs($this->owner)->get(route('expense-receipts.index'))->assertOk();
        $this->actingAs($this->owner)->get(route('expense-receipts.show', $receipt))->assertOk();
        $this->actingAs($this->owner)->get(route('expense-receipts.file', $receipt))->assertOk();
    }

    public function test_a_read_only_business_cannot_write_anything(): void
    {
        $receipt = $this->receiptInReview($this->owner);
        $this->makeReadOnly($this->owner);

        $this->actingAs($this->owner)->post(route('expense-receipts.store'), ['receipt' => $this->upload()])->assertForbidden();
        $this->actingAs($this->owner)->post(route('expense-receipts.confirm', $receipt), $this->confirmPayload())->assertForbidden();
        $this->actingAs($this->owner)->post(route('expense-receipts.retry', $receipt))->assertForbidden();
        $this->actingAs($this->owner)->post(route('expense-receipts.manual', $receipt))->assertForbidden();
        $this->actingAs($this->owner)->delete(route('expense-receipts.destroy', $receipt))->assertForbidden();

        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->refresh()->status);
        $this->assertSame(0, Expense::count());
        $this->assertSame(1, ExpenseReceipt::count());
    }

    public function test_a_plan_without_ocr_cannot_open_the_upload_page_or_upload(): void
    {
        $this->limitTo($this->owner, ['expenses.ocr_monthly_max' => 0]);

        $this->actingAs($this->owner)->get(route('expense-receipts.create'))->assertForbidden();
        $this->actingAs($this->owner)->post(route('expense-receipts.store'), ['receipt' => $this->upload()])->assertForbidden();
        $this->assertSame(0, ExpenseReceipt::count());
    }

    public function test_receipts_can_be_listed_even_when_the_plan_has_no_ocr(): void
    {
        $this->limitTo($this->owner, ['expenses.ocr_monthly_max' => 0]);

        $this->actingAs($this->owner)->get(route('expense-receipts.index'))->assertOk();
    }
}
