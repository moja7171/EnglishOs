<?php

use App\Models\DictionaryEntry;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The first version of the dictionary took any 404 from the relay for
     * "the dictionary does not know this word" and kept it for good, so
     * words looked up while the relay was still being set up are stored
     * with no meanings. Dropping every entry without meanings makes them
     * be asked again — a really unknown word just costs one more lookup.
     * A few rows at most, so it is filtered here rather than with a
     * database-specific JSON comparison.
     */
    public function up(): void
    {
        DictionaryEntry::query()
            ->each(function (DictionaryEntry $entry) {
                if ($entry->meanings === []) {
                    $entry->delete();
                }
            });
    }

    public function down(): void
    {
        // Nothing to restore: the deleted rows only held a gap.
    }
};
