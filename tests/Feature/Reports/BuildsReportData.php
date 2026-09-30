<?php

namespace Tests\Feature\Reports;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Shared helpers for report tests. "Today" is Tuesday 29 Sep 2026 in Asia/Kuala_Lumpur.
 */
trait BuildsReportData
{
    protected User $user;

    protected function setUpBuildsReportData(): void
    {
        $this->travelTo('2026-09-29 10:00:00');
        $this->user = User::factory()->create(['name' => 'Aisha Rahman']);
    }

    /**
     * Create an invoice for $owner in the given state ("draft", "issued", "paid", "cancelled").
     */
    protected function invoice(string $state, array $attributes = [], ?User $owner = null): Invoice
    {
        $factory = Invoice::factory();
        if ($state !== 'draft') {
            $factory = $factory->{$state}();
        }

        return $factory->ownedBy($owner ?? $this->user)->create($attributes);
    }

    protected function expense(array $attributes = [], ?User $owner = null): Expense
    {
        return Expense::factory()->ownedBy($owner ?? $this->user)->create($attributes);
    }

    protected function customer(array $attributes = [], ?User $owner = null): Customer
    {
        return Customer::factory()->ownedBy($owner ?? $this->user)->create($attributes);
    }

    protected function report(string $route, array $query = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->user)->get(route($route, $query))->assertOk();
    }

    /**
     * The text of the element carrying $attribute: tags stripped, entities decoded,
     * whitespace collapsed. Empty string if the element is not on the page.
     */
    protected function block(TestResponse $response, string $attribute): string
    {
        $html = $response->getContent();
        $start = strpos($html, $attribute);
        if ($start === false) {
            return '';
        }
        $tagStart = strrpos(substr($html, 0, $start), '<');
        preg_match('/<(\w+)/', substr($html, $tagStart), $tag);
        $depth = 0;
        $offset = $tagStart;
        while (preg_match('/<(\/?)'.$tag[1].'\b[^>]*>/', $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $depth += $m[1][0] === '/' ? -1 : 1;
            $offset = $m[0][1] + strlen($m[0][0]);
            if ($depth === 0) {
                break;
            }
        }

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(substr($html, $tagStart, $offset - $tagStart)))));
    }

    /**
     * The main figure on a summary card, e.g. "RM 1,500.00".
     */
    protected function card(TestResponse $response, string $card): string
    {
        preg_match('/data-report-card="'.$card.'".*?fs-4[^>]*>(.*?)<\/div>/s', $response->getContent(), $m);

        return trim(html_entity_decode($m[1] ?? 'missing'));
    }
}
