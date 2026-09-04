<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Old-to-new data mapping is no longer part of the ERP runtime model.
     * Legacy administrator IDs remain outside this migration because they are
     * the current login/RBAC identity keys, not business-data mappings.
     */
    public function up(): void
    {
        Schema::dropIfExists('erp_legacy_mappings');
        Schema::dropIfExists('legacy_scan_decisions');
        Schema::dropIfExists('legacy_scan_mappings');
    }

    public function down(): void
    {
        // Intentionally irreversible: mapping records must not be recreated.
    }
};
