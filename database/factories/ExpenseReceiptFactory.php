<?php

namespace Database\Factories;

use App\Enums\ExpenseReceiptStatus;
use App\Models\Business;
use App\Models\ExpenseReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Builds receipt rows directly for tests that need one in a given state. Real rows are always
 * written through ExpenseReceiptService. The factory writes no file; see the test helper.
 *
 * @extends Factory<ExpenseReceipt>
 */
class ExpenseReceiptFactory extends Factory
{
    /**
     * Define the model's default state: a freshly queued PNG that holds one unit of allowance.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'uploaded_by' => null,
            'status' => ExpenseReceiptStatus::Queued,
            'original_filename' => 'receipt.png',
            'mime_type' => 'image/png',
            'size_bytes' => 100,
            'sha256' => hash('sha256', Str::random(40)),
            'storage_path' => 'factory/'.Str::ulid()->toBase32().'.png',
            'attempts' => 0,
            'counted_at' => now(),
            'queued_at' => now(),
        ];
    }

    public function status(ExpenseReceiptStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    /**
     * Extracted and waiting for the user, with a plain extraction.
     *
     * @param  array<string, string|null>  $values  field name => value
     */
    public function inReview(array $values = []): static
    {
        $values += ['merchant' => 'Test Merchant Sdn Bhd', 'date' => '2026-09-15', 'total' => '42.50'];
        $fields = [];

        foreach ($values as $name => $value) {
            $fields[$name] = ['value' => $value, 'confidence' => 0.95];
        }

        return $this->state([
            'status' => ExpenseReceiptStatus::Review,
            'provider' => 'fake',
            'provider_model' => 'fake-1',
            'attempts' => 1,
            'extraction' => ['fields' => $fields, 'warnings' => [], 'manual' => false],
            'extracted_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(['status' => ExpenseReceiptStatus::Failed, 'counted_at' => null, 'failed_at' => now(), 'attempts' => 1, 'last_error' => 'The OCR service could not be reached.']);
    }
}
