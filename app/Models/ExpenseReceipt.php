<?php

namespace App\Models;

use App\Enums\ExpenseReceiptStatus;
use Database\Factories\ExpenseReceiptFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * An uploaded receipt and what OCR made of it, owned by a business through business_id.
 *
 * Nothing is mass assignable: ownership comes from the business's expenseReceipts()
 * relationship and every other field is set by ExpenseReceiptService, never from request
 * input. uploaded_by and confirmed_by are audit metadata only, never ownership.
 *
 * extraction is the normalized result, never a raw provider response:
 * {"fields": {"total": {"value": "42.50", "confidence": 0.93}, ...},
 *  "warnings": [{"code": "...", "field": "total"|null, "message": "..."}], "manual": bool}
 */
class ExpenseReceipt extends Model
{
    /** @use HasFactory<ExpenseReceiptFactory> */
    use HasFactory;

    /**
     * The private disk receipt files live on. There is no public URL for it.
     */
    public const DISK = 'receipts';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ExpenseReceiptStatus::class,
            'size_bytes' => 'integer',
            'attempts' => 'integer',
            'extraction' => 'array',
            'edited_fields' => 'array',
            'counted_at' => 'datetime',
            'queued_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'extracted_at' => 'datetime',
            'failed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'discarded_at' => 'datetime',
            'file_deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The expense created from this receipt; null until confirmed, and again if it was deleted.
     *
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /**
     * Audit metadata only: who uploaded it. Never use it for authorization.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Audit metadata only: who confirmed it. Never use it for authorization.
     *
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /**
     * Whether the stored file is still there (it is removed on discard and by pruning).
     */
    public function hasFile(): bool
    {
        return $this->file_deleted_at === null && $this->disk()->exists($this->storage_path);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Whether a fake provider produced the extraction (demo data, not read from the receipt).
     */
    public function isFake(): bool
    {
        return $this->provider === 'fake';
    }

    /**
     * Whether the user is filling the form in by hand instead of from an extraction.
     */
    public function isManual(): bool
    {
        return (bool) ($this->extraction['manual'] ?? false);
    }

    /**
     * An extracted field's normalized value, or null when it is missing.
     */
    public function field(string $name): ?string
    {
        $value = $this->extraction['fields'][$name]['value'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * An extracted field's confidence (0 to 1), or null when the provider gave none.
     */
    public function confidence(string $name): ?float
    {
        $value = $this->extraction['fields'][$name]['confidence'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return list<array{code: string, field: string|null, message: string}>
     */
    public function warnings(): array
    {
        return array_values($this->extraction['warnings'] ?? []);
    }

    /**
     * The Expense form values the extraction suggests. Subtotal, tax, receipt number, payment
     * method and a non-MYR currency have no expense column, so they go into the editable notes.
     *
     * @return array{expense_date: ?string, category: ?string, description: ?string, amount: ?string, payee: ?string, notes: ?string}
     */
    public function prefill(): array
    {
        $merchant = $this->field('merchant');
        $currency = $this->field('currency');

        $notes = array_filter([
            $this->field('receipt_number') ? 'Receipt no: '.$this->field('receipt_number') : null,
            $this->field('subtotal') ? 'Subtotal: '.$this->field('subtotal') : null,
            $this->field('tax') ? 'SST/tax: '.$this->field('tax') : null,
            $currency !== null && $currency !== config('bizflow.currency.code') ? 'Receipt currency: '.$currency : null,
            $this->field('payment_method') ? 'Paid by: '.$this->field('payment_method') : null,
        ]);

        return [
            'expense_date' => $this->field('date'),
            'category' => $this->field('category'),
            'description' => $this->field('description') ?? ($merchant !== null ? $merchant.' receipt' : null),
            'amount' => $this->field('total'),
            'payee' => $merchant,
            'notes' => $notes === [] ? null : implode("\n", $notes),
        ];
    }
}
