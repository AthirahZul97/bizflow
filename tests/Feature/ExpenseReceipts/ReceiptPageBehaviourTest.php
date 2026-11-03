<?php

namespace Tests\Feature\ExpenseReceipts;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The receipt pages' small behaviours (double-submit guard, "read again?" confirmation, the
 * "reading..." auto-refresh) moved from inline handlers to data attributes handled by
 * resources/js/page-behaviours.js, so a Content-Security-Policy can refuse inline script.
 *
 * What these tests can show is that the right elements carry the right attributes and that nothing
 * inline is left. That the browser then behaves correctly is NOT verified here: there is no browser
 * automation in this project (see the report), so the script's runtime behaviour needs a manual check.
 */
class ReceiptPageBehaviourTest extends TestCase
{
    use HandlesReceipts;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpReceipts();
        $this->owner = User::factory()->create();
    }

    private function assertNothingInline(string $html): void
    {
        $this->assertDoesNotMatchRegularExpression('/\son(?:submit|click|change|load)\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script\b(?![^>]*\bsrc=)/i', $html);
    }

    public function test_the_upload_form_disables_its_buttons_on_submit(): void
    {
        $html = $this->actingAs($this->owner)->get(route('expense-receipts.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<form[^>]*action="[^"]*expense-receipts"[^>]*enctype="multipart\/form-data"[^>]*data-disable-on-submit/s', $html);
        $this->assertNothingInline($html);
    }

    public function test_the_waiting_page_refreshes_itself_without_inline_script(): void
    {
        $receipt = $this->uploadFor($this->owner);

        $html = $this->actingAs($this->owner)->get(route('expense-receipts.show', $receipt))->assertOk()->getContent();

        $this->assertStringContainsString('data-auto-reload="3000"', $html);
        $this->assertStringContainsString('Reading your receipt', $html);
        $this->assertNothingInline($html);
    }

    public function test_the_review_page_guards_confirm_and_asks_before_reading_again(): void
    {
        $receipt = $this->receiptInReview($this->owner);

        $html = $this->actingAs($this->owner)->get(route('expense-receipts.show', $receipt))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<form[^>]*action="[^"]*\/confirm"[^>]*data-disable-on-submit/s', $html, 'confirm cannot be submitted twice');
        $this->assertMatchesRegularExpression('/<form[^>]*action="[^"]*\/retry"[^>]*data-confirm="Read the receipt again\? Your edits on this page will be lost\."/s', $html);
        $this->assertStringContainsString('data-testid="fake-ocr-banner"', $html, 'the demo reader is still labelled');
        $this->assertNothingInline($html);
    }

    public function test_the_forms_still_post_to_the_same_routes_with_the_same_protection(): void
    {
        $receipt = $this->receiptInReview($this->owner);

        $html = $this->actingAs($this->owner)->get(route('expense-receipts.show', $receipt))->getContent();

        $this->assertStringContainsString(route('expense-receipts.confirm', $receipt), $html);
        $this->assertStringContainsString(route('expense-receipts.retry', $receipt), $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'name="_token"'), 'each form still carries its CSRF token');
    }

    public function test_the_script_implements_each_data_attribute_the_views_use(): void
    {
        $script = file_get_contents(resource_path('js/page-behaviours.js'));

        foreach (['data-disable-on-submit', 'dataset.confirm', 'data-auto-reload', "addEventListener('submit'", 'window.confirm', 'event.preventDefault()'] as $needle) {
            $this->assertStringContainsString($needle, $script);
        }

        $this->assertStringContainsString("import './page-behaviours';", file_get_contents(resource_path('js/app.js')));
        $this->assertStringNotContainsString('eval(', $script);
    }
}
