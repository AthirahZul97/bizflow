<?php

namespace App\Models;

use App\Enums\InvoiceEmailStatus;
use App\Enums\InvoiceStatus;
use Database\Factories\InvoiceEmailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request to email an invoice, and what happened to it.
 *
 * Owned through its invoice (invoice_id -> invoices.business_id); there is no
 * business_id here. requested_by is audit metadata only.
 */
class InvoiceEmail extends Model
{
    /** @use HasFactory<InvoiceEmailFactory> */
    use HasFactory;

    /**
     * Recipient sources: the address copied onto the invoice, or the customer's current one.
     */
    public const SOURCE_INVOICE = 'invoice';

    public const SOURCE_CUSTOMER = 'customer';

    /**
     * Nothing is mass assignable: every field is set by InvoiceEmailService.
     *
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
            'status' => InvoiceEmailStatus::class,
            'status_at_send' => InvoiceStatus::class,
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get the person who asked for the send. Audit metadata only: NULL means it was
     * system-generated or has no associated person. Never use it for authorization
     * or tenant filtering; ownership comes through the invoice.
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
