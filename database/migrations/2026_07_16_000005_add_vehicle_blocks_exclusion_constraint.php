<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement("ALTER TABLE vehicle_blocks ADD CONSTRAINT vehicle_blocks_no_active_overlap_excl EXCLUDE USING gist (tenant_id WITH =, vehicle_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (status = 'active')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE vehicle_blocks DROP CONSTRAINT IF EXISTS vehicle_blocks_no_active_overlap_excl');
    }
};
