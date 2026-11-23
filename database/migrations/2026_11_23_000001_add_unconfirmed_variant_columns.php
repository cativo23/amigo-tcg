<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            // The set endpoint's variant counts (normal, holo, reverse, firstEd);
            // null until the set has been synced with a payload that carries them.
            $table->jsonb('variant_counts')->nullable();
        });

        Schema::table('cards', function (Blueprint $table) {
            // The base variants deduced for a card whose print list the catalog
            // source has not confirmed yet; null when the rule does not apply.
            $table->jsonb('inferred_variants')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn('inferred_variants');
        });

        Schema::table('sets', function (Blueprint $table) {
            $table->dropColumn('variant_counts');
        });
    }
};
