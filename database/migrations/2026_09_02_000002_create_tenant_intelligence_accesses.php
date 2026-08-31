<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CAPABILITIES = [
        'demand_forecast',
        'fleet_reallocation',
        'rental_usage_anomaly',
        'vehicle_color',
        'vehicle_plate',
        'vehicle_damage',
    ];

    public function up(): void
    {
        Schema::create('tenant_intelligence_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('capability', 64);
            $table->boolean('enabled')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'capability']);
            $table->index(['capability', 'enabled']);
        });

        $capabilities = collect(self::CAPABILITIES)
            ->map(fn (string $capability): string => DB::getPdo()->quote($capability))
            ->implode(', ');

        DB::statement("ALTER TABLE tenant_intelligence_accesses ADD CONSTRAINT tenant_intelligence_accesses_capability_check CHECK (capability IN ({$capabilities}))");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION rentfleet_guard_tenant_intelligence_access()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'tenant intelligence accesses cannot be physically deleted' USING ERRCODE = '23514';
                END IF;

                IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                   OR NEW.capability IS DISTINCT FROM OLD.capability
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'tenant intelligence access scope is immutable' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER tenant_intelligence_accesses_guard
            BEFORE UPDATE OR DELETE ON tenant_intelligence_accesses
            FOR EACH ROW EXECUTE FUNCTION rentfleet_guard_tenant_intelligence_access()
        SQL);

        $values = collect(self::CAPABILITIES)
            ->map(fn (string $capability): string => '('.DB::getPdo()->quote($capability).')')
            ->implode(', ');

        DB::statement(<<<SQL
            INSERT INTO tenant_intelligence_accesses
                (tenant_id, capability, enabled, updated_by, changed_at, created_at, updated_at)
            SELECT tenants.id, capabilities.capability, TRUE, NULL,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM tenants
            CROSS JOIN (VALUES {$values}) AS capabilities(capability)
            ON CONFLICT (tenant_id, capability) DO NOTHING
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS tenant_intelligence_accesses_guard ON tenant_intelligence_accesses');
        DB::statement('DROP FUNCTION IF EXISTS rentfleet_guard_tenant_intelligence_access()');
        Schema::dropIfExists('tenant_intelligence_accesses');
    }
};
