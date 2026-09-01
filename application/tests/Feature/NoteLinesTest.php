<?php

namespace Tests\Feature;

use App\Services\NoteLines;
use Tests\TestCase;

/**
 * A note is a list of instructions, and should read as one.
 */
class NoteLinesTest extends TestCase
{
    public function test_separate_lines_are_the_items(): void
    {
        $this->assertSame(
            ['Move the logo to the left chest', 'Make the back text bigger'],
            NoteLines::bullets("Move the logo to the left chest\nMake the back text bigger")
        );
    }

    public function test_a_typed_bullet_or_number_is_not_repeated(): void
    {
        $this->assertSame(
            ['Alisin ang malaking text', 'Lagyan ng IC logo', 'Update the woven tag'],
            NoteLines::bullets("- Alisin ang malaking text\n2. Lagyan ng IC logo\n• Update the woven tag")
        );
    }

    public function test_one_run_of_prose_is_split_on_its_sentences(): void
    {
        $note = 'Alisin na po yung malaking BOYS OF NORTH sa front. '
            .'Lagyan ng IC logo sa right chest. Paki lagyan po ng IC woven and tag.';

        $this->assertSame([
            'Alisin na po yung malaking BOYS OF NORTH sa front.',
            'Lagyan ng IC logo sa right chest.',
            'Paki lagyan po ng IC woven and tag.',
        ], NoteLines::bullets($note));
    }

    public function test_an_address_is_not_three_instructions(): void
    {
        $note = 'Deliver to Sto. Rosario St. Angeles City.';

        $this->assertSame([$note], NoteLines::bullets($note),
            'a full stop inside an abbreviation does not end an instruction');
    }

    public function test_one_instruction_stays_one_item(): void
    {
        $this->assertSame(['Make the logo bigger.'], NoteLines::bullets('Make the logo bigger.'));
        $this->assertFalse(NoteLines::isList('Make the logo bigger.'));
    }

    public function test_nothing_typed_is_nothing_shown(): void
    {
        $this->assertSame([], NoteLines::bullets(null));
        $this->assertSame([], NoteLines::bullets('   '));
        $this->assertFalse(NoteLines::isList(null));
    }

    public function test_blank_lines_between_items_are_dropped(): void
    {
        $this->assertSame(
            ['First thing', 'Second thing'],
            NoteLines::bullets("First thing\n\n\nSecond thing")
        );
    }
}
