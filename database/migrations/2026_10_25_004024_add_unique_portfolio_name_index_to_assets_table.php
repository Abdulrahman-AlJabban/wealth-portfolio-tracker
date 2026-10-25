<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A portfolio cannot have two private assets with the same name. Global assets have a null
     * portfolio_id, and NULLs are distinct in a unique index, so they are not affected.
     * With the utf8mb4_unicode_ci collation the check is case-insensitive ("Flat" equals "flat").
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->unique(['portfolio_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropUnique(['portfolio_id', 'name']);
        });
    }
};
