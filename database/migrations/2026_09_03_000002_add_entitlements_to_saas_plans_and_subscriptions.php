<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DEFAULT_ENTITLEMENTS = <<<'JSON'
        {"max_agencies":null,"max_users":null,"max_vehicles":null,"monthly_intelligence_runs":null,"intelligence_capabilities":["demand_forecast","fleet_reallocation","rental_usage_anomaly","vehicle_color","vehicle_plate","vehicle_damage"]}
        JSON;

    public function up(): void
    {
        $default = DB::getPdo()->quote(self::DEFAULT_ENTITLEMENTS);

        DB::unprepared(<<<SQL
            ALTER TABLE saas_plans
                ADD COLUMN entitlements jsonb NOT NULL DEFAULT {$default}::jsonb;

            ALTER TABLE saas_subscriptions
                ADD COLUMN entitlements jsonb NOT NULL DEFAULT {$default}::jsonb;

            UPDATE saas_subscriptions AS subscriptions
            SET entitlements = plans.entitlements
            FROM saas_plans AS plans
            WHERE plans.id = subscriptions.saas_plan_id;

            CREATE INDEX demand_forecast_exec_quota_usage_idx
                ON demand_forecast_execution_runs (tenant_id, requested_at);
            CREATE INDEX rental_anomaly_runs_quota_usage_idx
                ON rental_usage_anomaly_runs (tenant_id, requested_at);
            CREATE INDEX vehicle_color_runs_quota_usage_idx
                ON vehicle_color_prediction_runs (tenant_id, requested_at);
            CREATE INDEX vehicle_plate_runs_quota_usage_idx
                ON vehicle_plate_prediction_runs (tenant_id, requested_at);
            CREATE INDEX vehicle_damage_runs_quota_usage_idx
                ON vehicle_damage_prediction_runs (tenant_id, requested_at);

            CREATE OR REPLACE FUNCTION belkhir_valid_saas_entitlements(value jsonb) RETURNS boolean AS $$
            DECLARE
                quota_key text;
                capability text;
                seen_capabilities text[] := ARRAY[]::text[];
            BEGIN
                IF jsonb_typeof(value) <> 'object'
                   OR NOT value ?& ARRAY['max_agencies', 'max_users', 'max_vehicles', 'monthly_intelligence_runs', 'intelligence_capabilities']
                   OR value - ARRAY['max_agencies', 'max_users', 'max_vehicles', 'monthly_intelligence_runs', 'intelligence_capabilities'] <> '{}'::jsonb THEN
                    RETURN false;
                END IF;

                FOREACH quota_key IN ARRAY ARRAY['max_agencies', 'max_users', 'max_vehicles', 'monthly_intelligence_runs'] LOOP
                    IF jsonb_typeof(value->quota_key) NOT IN ('null', 'number') THEN
                        RETURN false;
                    END IF;
                    IF jsonb_typeof(value->quota_key) = 'number' THEN
                        IF (value->>quota_key) !~ '^[0-9]+$' THEN
                            RETURN false;
                        END IF;
                        IF (value->>quota_key)::numeric > CASE quota_key
                            WHEN 'max_agencies' THEN 10000
                            WHEN 'max_users' THEN 100000
                            WHEN 'max_vehicles' THEN 1000000
                            WHEN 'monthly_intelligence_runs' THEN 10000000
                        END THEN
                            RETURN false;
                        END IF;
                    END IF;
                END LOOP;

                IF jsonb_typeof(value->'intelligence_capabilities') <> 'array' THEN
                    RETURN false;
                END IF;
                FOR capability IN SELECT jsonb_array_elements_text(value->'intelligence_capabilities') LOOP
                    IF capability NOT IN ('demand_forecast', 'fleet_reallocation', 'rental_usage_anomaly', 'vehicle_color', 'vehicle_plate', 'vehicle_damage')
                       OR capability = ANY(seen_capabilities) THEN
                        RETURN false;
                    END IF;
                    seen_capabilities := array_append(seen_capabilities, capability);
                END LOOP;

                RETURN true;
            EXCEPTION WHEN OTHERS THEN
                RETURN false;
            END;
            $$ LANGUAGE plpgsql IMMUTABLE;

            ALTER TABLE saas_plans
                ADD CONSTRAINT saas_plans_entitlements_check
                    CHECK (belkhir_valid_saas_entitlements(entitlements));

            ALTER TABLE saas_subscriptions
                ADD CONSTRAINT saas_subscriptions_entitlements_check
                    CHECK (belkhir_valid_saas_entitlements(entitlements));

            CREATE OR REPLACE FUNCTION rentfleet_guard_saas_subscription() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'SaaS subscriptions cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                   OR NEW.saas_plan_id IS DISTINCT FROM OLD.saas_plan_id
                   OR NEW.billing_interval IS DISTINCT FROM OLD.billing_interval
                   OR NEW.price_amount IS DISTINCT FROM OLD.price_amount
                   OR NEW.currency IS DISTINCT FROM OLD.currency
                   OR NEW.entitlements IS DISTINCT FROM OLD.entitlements
                   OR NEW.starts_at IS DISTINCT FROM OLD.starts_at
                   OR NEW.created_by IS DISTINCT FROM OLD.created_by
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'SaaS subscription scope, price and entitlements snapshot are immutable' USING ERRCODE = '23514';
                END IF;

                IF OLD.status IN ('cancelled', 'expired') THEN
                    RAISE EXCEPTION 'Terminal SaaS subscriptions are immutable' USING ERRCODE = '23514';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                    (OLD.status = 'trialing' AND NEW.status IN ('active', 'suspended', 'cancelled', 'expired'))
                    OR (OLD.status = 'active' AND NEW.status IN ('past_due', 'suspended', 'cancelled', 'expired'))
                    OR (OLD.status = 'past_due' AND NEW.status IN ('active', 'suspended', 'cancelled', 'expired'))
                    OR (OLD.status = 'suspended' AND NEW.status IN ('active', 'past_due', 'cancelled', 'expired'))
                ) THEN
                    RAISE EXCEPTION 'Invalid SaaS subscription status transition' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rentfleet_guard_saas_subscription() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'SaaS subscriptions cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                   OR NEW.saas_plan_id IS DISTINCT FROM OLD.saas_plan_id
                   OR NEW.billing_interval IS DISTINCT FROM OLD.billing_interval
                   OR NEW.price_amount IS DISTINCT FROM OLD.price_amount
                   OR NEW.currency IS DISTINCT FROM OLD.currency
                   OR NEW.starts_at IS DISTINCT FROM OLD.starts_at
                   OR NEW.created_by IS DISTINCT FROM OLD.created_by
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'SaaS subscription scope and price snapshot are immutable' USING ERRCODE = '23514';
                END IF;

                IF OLD.status IN ('cancelled', 'expired') THEN
                    RAISE EXCEPTION 'Terminal SaaS subscriptions are immutable' USING ERRCODE = '23514';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                    (OLD.status = 'trialing' AND NEW.status IN ('active', 'suspended', 'cancelled', 'expired'))
                    OR (OLD.status = 'active' AND NEW.status IN ('past_due', 'suspended', 'cancelled', 'expired'))
                    OR (OLD.status = 'past_due' AND NEW.status IN ('active', 'suspended', 'cancelled', 'expired'))
                    OR (OLD.status = 'suspended' AND NEW.status IN ('active', 'past_due', 'cancelled', 'expired'))
                ) THEN
                    RAISE EXCEPTION 'Invalid SaaS subscription status transition' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            ALTER TABLE saas_subscriptions DROP CONSTRAINT saas_subscriptions_entitlements_check;
            ALTER TABLE saas_plans DROP CONSTRAINT saas_plans_entitlements_check;
            DROP INDEX IF EXISTS vehicle_damage_runs_quota_usage_idx;
            DROP INDEX IF EXISTS vehicle_plate_runs_quota_usage_idx;
            DROP INDEX IF EXISTS vehicle_color_runs_quota_usage_idx;
            DROP INDEX IF EXISTS rental_anomaly_runs_quota_usage_idx;
            DROP INDEX IF EXISTS demand_forecast_exec_quota_usage_idx;
            ALTER TABLE saas_subscriptions DROP COLUMN entitlements;
            ALTER TABLE saas_plans DROP COLUMN entitlements;
            DROP FUNCTION IF EXISTS belkhir_valid_saas_entitlements(jsonb);
        SQL);
    }
};
