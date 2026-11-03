<?php

namespace Tests\Feature\ExpenseReceipts;

use App\Enums\ExpenseReceiptStatus;
use App\Jobs\ProcessExpenseReceipt;
use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReceiptUploadTest extends TestCase
{
    use HandlesReceipts;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpReceipts();
        $this->user = User::factory()->create();
    }

    private function submit(?UploadedFile $file, array $extra = [])
    {
        return $this->actingAs($this->user)->post(route('expense-receipts.store'), ['receipt' => $file] + $extra);
    }

    private function assertNothingStored(): void
    {
        $this->assertSame(0, ExpenseReceipt::count());
        $this->assertSame([], Storage::disk(ExpenseReceipt::DISK)->allFiles());
        Queue::assertNothingPushed();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function acceptedFiles(): array
    {
        return [
            'png' => ['receipt.png', 'png'],
            'jpg' => ['receipt.jpg', 'jpeg'],
            'jpeg extension' => ['Receipt.JPEG', 'jpeg'],
            'pdf' => ['receipt.pdf', 'pdf'],
        ];
    }

    #[DataProvider('acceptedFiles')]
    public function test_a_valid_receipt_is_stored_privately_and_queued(string $name, string $kind): void
    {
        $bytes = match ($kind) {
            'png' => $this->png(),
            'jpeg' => $this->jpeg(),
            'pdf' => $this->pdf(),
        };

        $response = $this->submit($this->upload($name, $bytes));

        $receipt = ExpenseReceipt::sole();
        $response->assertRedirect(route('expense-receipts.show', $receipt));
        $this->assertSame(ExpenseReceiptStatus::Queued, $receipt->status);
        $this->assertSame($this->businessOf($this->user)->getKey(), $receipt->business_id);
        $this->assertSame($this->user->getKey(), $receipt->uploaded_by);
        $this->assertSame(hash('sha256', $bytes), $receipt->sha256);
        $this->assertSame(strlen($bytes), $receipt->size_bytes);
        $this->assertNotNull($receipt->counted_at);
        $this->assertSame($bytes, Storage::disk(ExpenseReceipt::DISK)->get($receipt->storage_path));
        Queue::assertPushed(ProcessExpenseReceipt::class, fn ($job) => $job->expenseReceiptId === $receipt->getKey());
        Queue::assertPushed(ProcessExpenseReceipt::class, 1);
        $this->assertSame(0, Expense::count(), 'uploading never creates an expense');
    }

    public function test_the_stored_path_is_generated_and_never_uses_the_client_filename(): void
    {
        $this->submit($this->upload('../../etc/passwd-secret receipt.png'));

        $receipt = ExpenseReceipt::sole();
        $this->assertMatchesRegularExpression('#^'.$receipt->business_id.'/[0-9a-z]{26}\.png$#', $receipt->storage_path);
        $this->assertStringNotContainsString('passwd', $receipt->storage_path);
        $this->assertStringNotContainsString('..', $receipt->storage_path);
        $this->assertSame([$receipt->storage_path], Storage::disk(ExpenseReceipt::DISK)->allFiles());
    }

    public function test_the_extension_comes_from_the_detected_type_and_the_display_name_is_cleaned(): void
    {
        $this->submit($this->upload('..\\..\\win\\"bad"<name>.JPG', $this->jpeg()));

        $receipt = ExpenseReceipt::sole();
        $this->assertStringEndsWith('.jpg', $receipt->storage_path);
        $this->assertSame('badname.jpg', $receipt->original_filename);
    }

    /**
     * @return array<string, array{string, string|null, string}>
     */
    public static function refusedFiles(): array
    {
        $php = "<?php echo 'owned'; ?>";

        return [
            'text file named jpg' => ['note.jpg', 'just some text', 'png'],
            'php script' => ['shell.php', $php, 'png'],
            'php script named png' => ['shell.png', $php, 'png'],
            'image header followed by php' => ['x.png', "\x89PNG\r\n\x1a\n".$php, 'png'],
            'jpeg magic with garbage' => ['x.jpg', "\xFF\xD8\xFF\xE0".str_repeat('A', 100), 'png'],
            'png content with jpg name' => ['x.jpg', null, 'png'],
            'jpeg content with png name' => ['x.png', null, 'jpeg'],
            'executable with image extension' => ['x.png', "MZ\x90\x00".str_repeat("\x00", 60), 'png'],
            'html named pdf' => ['x.pdf', '<html><script>alert(1)</script></html>', 'png'],
            'gif content' => ['x.gif', 'GIF89a'.str_repeat("\x00", 20), 'png'],
            'svg' => ['x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>', 'png'],
            'empty file' => ['x.png', '', 'png'],
        ];
    }

    #[DataProvider('refusedFiles')]
    public function test_files_that_are_not_valid_receipts_are_refused(string $name, ?string $bytes, string $fallback): void
    {
        $bytes ??= $fallback === 'png' ? $this->png() : $this->jpeg();
        // "png content with jpg name" and the reverse: the bytes are real, the name lies.

        $this->submit($this->upload($name, $bytes))->assertSessionHasErrors('receipt');

        $this->assertNothingStored();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function brokenPdfs(): array
    {
        return [
            'truncated (no EOF marker)' => ["%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n"],
            'password protected' => ["%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Encrypt 2 0 R>>\n%%EOF\n"],
            'javascript' => ["%PDF-1.4\n1 0 obj<</S/JavaScript/JS(app.alert(1))>>endobj\n%%EOF\n"],
            'launch action' => ["%PDF-1.4\n1 0 obj<</S/Launch>>endobj\n%%EOF\n"],
            'embedded file' => ["%PDF-1.4\n1 0 obj<</Type/EmbeddedFile>>endobj\n%%EOF\n"],
            'open action' => ["%PDF-1.4\n1 0 obj<</OpenAction 2 0 R>>endobj\n%%EOF\n"],
        ];
    }

    #[DataProvider('brokenPdfs')]
    public function test_incomplete_protected_or_active_pdfs_are_refused(string $bytes): void
    {
        $this->submit($this->upload('x.pdf', $bytes))->assertSessionHasErrors('receipt');

        $this->assertNothingStored();
    }

    public function test_an_oversized_file_is_refused(): void
    {
        config(['ocr.max_upload_kb' => 1]);

        $this->submit($this->upload('big.png', $this->png().str_repeat('0', 2048)))->assertSessionHasErrors('receipt');

        $this->assertNothingStored();
    }

    public function test_a_file_at_the_size_limit_is_accepted(): void
    {
        config(['ocr.max_upload_kb' => 1]);

        $this->submit($this->upload('ok.png', $this->png().str_repeat('0', 1024 - strlen($this->png()))))->assertSessionDoesntHaveErrors();

        $this->assertSame(1, ExpenseReceipt::count());
    }

    public function test_an_image_with_too_many_pixels_is_refused(): void
    {
        config(['ocr.max_image_pixels' => 0]);

        $this->submit($this->upload())->assertSessionHasErrors('receipt');

        $this->assertNothingStored();
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->submit(null)->assertSessionHasErrors('receipt');

        $this->assertNothingStored();
    }

    public function test_a_failed_php_upload_is_refused_with_a_clear_message(): void
    {
        $file = new UploadedFile($this->upload()->getPathname(), 'receipt.png', 'image/png', UPLOAD_ERR_INI_SIZE, true);

        $this->submit($file)->assertSessionHasErrors('receipt');

        $this->assertNothingStored();
    }

    public function test_a_forged_business_status_or_expense_in_the_request_is_ignored(): void
    {
        $other = User::factory()->create();

        $this->submit($this->upload(), [
            'business_id' => $this->businessOf($other)->getKey(),
            'status' => 'confirmed',
            'expense_id' => 1,
            'uploaded_by' => $other->getKey(),
            'counted_at' => null,
        ]);

        $receipt = ExpenseReceipt::sole();
        $this->assertSame($this->businessOf($this->user)->getKey(), $receipt->business_id);
        $this->assertSame(ExpenseReceiptStatus::Queued, $receipt->status);
        $this->assertNull($receipt->expense_id);
        $this->assertSame($this->user->getKey(), $receipt->uploaded_by);
        $this->assertNotNull($receipt->counted_at);
    }

    public function test_business_id_is_not_mass_assignable(): void
    {
        $this->assertSame([], (new ExpenseReceipt)->getFillable());
    }

    public function test_uploading_is_refused_when_scanning_is_switched_off(): void
    {
        config(['ocr.driver' => 'none']);

        $this->actingAs($this->user)->get(route('expense-receipts.create'))->assertRedirect(route('expense-receipts.index'));
        $this->submit($this->upload())->assertSessionHas('error');

        $this->assertNothingStored();
    }

    public function test_a_failed_save_leaves_no_file_behind(): void
    {
        ExpenseReceipt::creating(fn () => throw new \RuntimeException('boom'));

        try {
            $this->withoutExceptionHandling()->actingAs($this->user)->post(route('expense-receipts.store'), ['receipt' => $this->upload()]);
            $this->fail('the save should have failed');
        } catch (\RuntimeException) {
            // expected
        } finally {
            ExpenseReceipt::flushEventListeners();
        }

        $this->assertNothingStored();
    }

    public function test_the_upload_page_works_and_is_mobile_friendly(): void
    {
        $html = $this->actingAs($this->user)->get(route('expense-receipts.create'))->assertOk()->getContent();

        $this->assertStringContainsString('name="viewport" content="width=device-width, initial-scale=1"', $html);
        $this->assertStringContainsString('capture="environment"', $html);
        $this->assertStringContainsString('accept="image/jpeg,image/png,application/pdf"', $html);
        $this->assertStringContainsString('multipart/form-data', $html);
        $this->assertStringNotContainsString('multiple', $html, 'no batch upload');
    }

    public function test_the_demo_provider_is_labelled_everywhere_it_appears(): void
    {
        config(['ocr.driver' => 'fake']);

        $this->actingAs($this->user)->get(route('expense-receipts.create'))->assertSee('Demo OCR');
        $this->actingAs($this->user)->get(route('expense-receipts.index'))->assertSee('Demo OCR');

        $receipt = $this->receiptInReview($this->user);
        $this->actingAs($this->user)->get(route('expense-receipts.show', $receipt))->assertSee('Demo OCR');
    }

    public function test_no_banner_without_the_demo_provider(): void
    {
        config(['ocr.driver' => 'none']);

        $this->actingAs($this->user)->get(route('expense-receipts.index'))->assertDontSee('Demo OCR');
    }
}
