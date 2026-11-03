<?php

namespace Tests\Feature\ExpenseReceipts;

use App\Jobs\ProcessExpenseReceipt;
use App\Models\ExpenseReceipt;
use App\Models\User;
use App\Ocr\ReceiptExtractionNormalizer;
use App\Ocr\ReceiptOcrProvider;
use App\Services\ExpenseReceiptService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Helpers for receipt tests. Call setUpReceipts() from setUp(): it fakes the private receipts
 * disk and the queue (jobs are run by hand so every step is controlled) and fixes "now".
 * Users come from the factory with a Legacy subscription, which has no OCR limit.
 */
trait HandlesReceipts
{
    protected function setUpReceipts(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        Storage::fake(ExpenseReceipt::DISK);
        Queue::fake();
    }

    protected function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    protected function jpeg(): string
    {
        return base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    }

    protected function pdf(string $extra = ''): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n{$extra}trailer<</Root 1 0 R>>\n%%EOF\n";
    }

    protected function upload(string $name = 'receipt.png', ?string $bytes = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes ?? $this->png());
    }

    /**
     * A distinct valid PNG each call (the file's hash differs), for tests that upload several.
     */
    protected function uniquePng(): string
    {
        return $this->png().random_bytes(8);
    }

    /**
     * Upload a receipt through the service, as the user's business.
     */
    protected function uploadFor(User $user, ?UploadedFile $file = null): ExpenseReceipt
    {
        return app(ExpenseReceiptService::class)->upload($this->businessOf($user), $user, $file ?? $this->upload('r.png', $this->uniquePng()));
    }

    /**
     * Run the job by hand, with the configured provider or the one given.
     */
    protected function runJob(ExpenseReceipt $receipt, ?ReceiptOcrProvider $provider = null): void
    {
        (new ProcessExpenseReceipt($receipt->getKey()))->handle(
            app(ExpenseReceiptService::class),
            $provider ?? app(ReceiptOcrProvider::class),
            app(ReceiptExtractionNormalizer::class),
        );
    }

    /**
     * A receipt uploaded and read by the (fake) provider: waiting in review.
     */
    protected function receiptInReview(User $user): ExpenseReceipt
    {
        $receipt = $this->uploadFor($user);
        $this->runJob($receipt);

        return $receipt->refresh();
    }

    /**
     * A valid confirm payload (the Expense form's fields).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function confirmPayload(array $overrides = []): array
    {
        return $overrides + [
            'expense_date' => '2026-09-20',
            'category' => 'office',
            'description' => 'Printer paper',
            'amount' => '25.90',
            'payee' => 'Kedai Runcit Ali',
            'notes' => 'Edited by the user',
        ];
    }
}
