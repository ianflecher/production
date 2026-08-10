<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `job_orders` is one wide table and it keeps getting wider.
 *
 * InnoDB refuses a row whose columns could exceed 65,535 bytes, and a
 * `varchar(255)` in utf8mb4 reserves 1,020 of them whether or not anything is
 * stored. The sewing block added twenty-two columns in one go and took the
 * table to roughly two thirds of the limit. The next batch that size would be
 * refused — by MySQL, at migration time, on the live database, which is a
 * terrible place to find out.
 *
 * So the estimate is checked here instead. The suite runs on SQLite, which has
 * no such limit, but the schema comes from the same migrations — so the shape
 * is right even though the engine is not.
 *
 * If this fails: the fix is not a bigger number below. It is that the sewing
 * fields (or whichever group has grown) want their own table.
 */
class JobOrderRowSizeTest extends TestCase
{
    use RefreshDatabase;

    /** InnoDB's hard limit on the sum of a row's column widths. */
    private const INNODB_ROW_LIMIT = 65535;

    /** Fail while there is still room to add a normal batch of fields. */
    private const HEADROOM_REQUIRED = 12000;

    public function test_job_orders_still_fits_inside_an_innodb_row_with_room_to_spare(): void
    {
        $bytes = $this->estimatedRowBytes('job_orders');
        $headroom = self::INNODB_ROW_LIMIT - $bytes;

        $this->assertGreaterThan(0, $headroom, sprintf(
            'job_orders no longer fits in an InnoDB row: about %d bytes against a limit of %d. '.
            'MySQL will refuse the migration. Move a group of columns to their own table.',
            $bytes, self::INNODB_ROW_LIMIT
        ));

        $this->assertGreaterThan(self::HEADROOM_REQUIRED, $headroom, sprintf(
            'job_orders is within %d bytes of the InnoDB row limit (about %d of %d used). '.
            'It still works, but the next group of fields will not fit. '.
            'Give the newest group its own table rather than raising this threshold.',
            $headroom, $bytes, self::INNODB_ROW_LIMIT
        ));
    }

    /**
     * What MySQL would reserve for one row, from the schema the migrations
     * build. Deliberately pessimistic — it is a warning line, not an audit.
     */
    private function estimatedRowBytes(string $table): int
    {
        $bytes = 0;

        foreach (Schema::getColumns($table) as $column) {
            $type = strtolower($column['type_name'] ?? $column['type'] ?? '');

            $bytes += match (true) {
                // utf8mb4 reserves four bytes per character, plus a length prefix.
                str_contains($type, 'char') => $this->declaredLength($column) * 4 + 2,
                // Blobs and texts keep a pointer in the row, not the content.
                str_contains($type, 'text'), str_contains($type, 'blob'), str_contains($type, 'json') => 20,
                default => 8,
            };
        }

        return $bytes;
    }

    /** The declared width of a char column, defaulting to Laravel's 255. */
    private function declaredLength(array $column): int
    {
        if (preg_match('/\((\d+)\)/', (string) ($column['type'] ?? ''), $m)) {
            return (int) $m[1];
        }

        return 255;
    }
}
