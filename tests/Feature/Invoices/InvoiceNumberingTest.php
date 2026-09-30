<?php

namespace Tests\Feature\Invoices;

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceNumberGenerator;
use App\Services\InvoiceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Invoice numbers are a per-business sequence.
 */
class InvoiceNumberingTest extends TestCase
{
    use CreatesInvoices;
    use RefreshDatabase;

    public function test_each_business_has_its_own_sequence_starting_at_one(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->assertSame('INV-00001', $this->issuedFor($alice)->invoice_number);
        $this->assertSame('INV-00001', $this->issuedFor($bob)->invoice_number);
        $this->assertSame('INV-00002', $this->issuedFor($alice)->invoice_number);
        $this->assertSame('INV-00003', $this->issuedFor($alice)->invoice_number);
        $this->assertSame('INV-00002', $this->issuedFor($bob)->invoice_number);

        $this->assertSame([1, 2, 3], $this->businessOf($alice)->invoices()->orderBy('invoice_sequence')->pluck('invoice_sequence')->all());
        $this->assertSame([1, 2], $this->businessOf($bob)->invoices()->orderBy('invoice_sequence')->pluck('invoice_sequence')->all());
    }

    public function test_a_new_business_starts_at_one_whatever_other_businesses_have_issued(): void
    {
        $busy = User::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->issuedFor($busy);
        }

        $newcomer = User::factory()->create();

        $this->assertSame('INV-00001', $this->issuedFor($newcomer)->invoice_number);
    }

    public function test_the_same_number_cannot_exist_twice_in_one_business(): void
    {
        $user = User::factory()->create();
        $first = $this->issuedFor($user);
        $draft = $this->draftFor($user);

        $this->expectException(UniqueConstraintViolationException::class);

        $draft->forceFill(['invoice_number' => $first->invoice_number, 'invoice_sequence' => 99])->save();
    }

    public function test_the_same_sequence_cannot_exist_twice_in_one_business(): void
    {
        $user = User::factory()->create();
        $first = $this->issuedFor($user);
        $draft = $this->draftFor($user);

        $this->expectException(UniqueConstraintViolationException::class);

        $draft->forceFill(['invoice_number' => 'INV-99999', 'invoice_sequence' => $first->invoice_sequence])->save();
    }

    public function test_the_generator_numbers_from_the_invoices_business(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $this->issuedFor($alice);
        $this->issuedFor($alice);
        $draft = $this->draftFor($bob);

        DB::transaction(fn () => app(InvoiceNumberGenerator::class)->assign($draft));

        $this->assertSame('INV-00001', $draft->invoice_number);
        $this->assertSame(1, $draft->invoice_sequence);
    }

    public function test_issuing_keeps_the_creator_and_business(): void
    {
        $user = User::factory()->create();
        $invoice = app(InvoiceService::class)->issue($this->draftFor($user));

        $invoice = Invoice::findOrFail($invoice->id);
        $this->assertSame($this->businessOf($user)->id, $invoice->business_id);
        $this->assertSame($user->id, $invoice->created_by);
    }
}
