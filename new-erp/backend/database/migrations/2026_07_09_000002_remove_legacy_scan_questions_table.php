<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('legacy_scan_questions');
    }

    public function down(): void
    {
        // Questions are documentation-owned and must not be recreated as application data.
    }
};
