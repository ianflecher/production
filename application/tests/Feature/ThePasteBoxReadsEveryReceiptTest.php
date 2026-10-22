<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The paste box says what the reader is doing, for both readers.
 *
 * The box can read a reference number off a receipt two ways: into one named
 * field, or one per receipt when several are attached at once. Both report
 * progress through the same line of text, and that line was only rendered for
 * the first of them.
 *
 * readNumberInto() opens by looking for it and returns when it is not there,
 * so the multi-receipt form - which asks for a reference list and no single
 * target - read nothing, filled nothing in, and said nothing about it. It
 * looked like OCR that could not read the picture. It never ran.
 */
class ThePasteBoxReadsEveryReceiptTest extends TestCase
{
    /**
     * The status line as it is written into the page.
     *
     * Not the bare attribute: the script that looks for the line names it too,
     * so searching for that alone finds the search itself and passes on a page
     * where the line was never rendered.
     */
    private const STATUS_LINE = '<div class="paste-drop-ocr" data-ocr-status';

    private function box(array $with): string
    {
        return view('partials.paste-image-field', array_merge([
            'name' => 'proof',
        ], $with))->render();
    }

    /** The case that was broken: several receipts, a reference for each. */
    public function test_the_reference_list_box_can_report_progress(): void
    {
        $html = $this->box(['referenceListName' => 'proof_references', 'multiple' => true]);

        $this->assertStringContainsString('data-reference-list data-reference-name', $html);
        $this->assertStringContainsString(self::STATUS_LINE, $html,
            'the reader has nowhere to say what it is doing, so it does not run');
    }

    /** The single-field case still has it. */
    public function test_the_single_field_box_still_reports_progress(): void
    {
        $html = $this->box(['ocrTarget' => 'reference']);

        $this->assertStringContainsString('data-ocr-target="reference"', $html);
        $this->assertStringContainsString(self::STATUS_LINE, $html);
    }

    /** A box that reads nothing says nothing - no stray empty line on the form. */
    public function test_a_plain_box_has_no_status_line(): void
    {
        $html = $this->box([]);

        $this->assertStringNotContainsString(self::STATUS_LINE, $html);
        $this->assertStringNotContainsString('data-reference-list data-reference-name', $html);
    }

    /** Both at once is allowed, and gets one line between them. */
    public function test_both_readers_share_the_one_line(): void
    {
        $html = $this->box(['ocrTarget' => 'reference', 'referenceListName' => 'proof_references']);

        $this->assertSame(1, substr_count($html, self::STATUS_LINE));
    }
}
