$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$script:AcceptanceDatabase = 'rentfleet_06g_acceptance'
$script:ProtectedDatabases = @('rentfleet', 'rentfleet_test', 'rentfleet_restore_test')
$script:ExpectedHost = '127.0.0.1'
$script:ExpectedPort = 5432
$script:ExpectedUser = 'rentfleet_app'
$script:AcceptanceRoot = [System.IO.Path]::GetFullPath('C:\tmp\RentFleet06G')

function Test-QaPathWithin {
    param(
        [Parameter(Mandatory)][string]$Candidate,
        [Parameter(Mandatory)][string]$Parent
    )

    $separator = [System.IO.Path]::DirectorySeparatorChar
    $prefix = $Parent.TrimEnd('\', '/') + $separator

    return $Candidate.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)
}

function Resolve-QaRunRoot {
    param([Parameter(Mandatory)][string]$RunRoot)

    if ([string]::IsNullOrWhiteSpace($RunRoot)) {
        throw 'La racine temporaire 06G est obligatoire.'
    }

    $resolved = [System.IO.Path]::GetFullPath($RunRoot)
    if (-not (Test-QaPathWithin -Candidate $resolved -Parent $script:AcceptanceRoot)) {
        throw "Chemin refusé : la racine doit être un sous-dossier de '$script:AcceptanceRoot'."
    }

    $relative = $resolved.Substring($script:AcceptanceRoot.Length).TrimStart('\', '/')
    if ($relative -notmatch '^[A-Za-z0-9][A-Za-z0-9_-]{5,63}$') {
        throw 'Le run-id 06G doit contenir entre 6 et 64 caractères sûrs.'
    }

    return $resolved
}

function Assert-QaEnvironment {
    if ($env:APP_ENV -ne 'testing') {
        throw 'Recette refusée : APP_ENV doit être exactement testing.'
    }
    if ($env:DB_CONNECTION -ne 'pgsql') {
        throw 'Recette refusée : DB_CONNECTION doit être exactement pgsql.'
    }
    if ($env:RENTFLEET_ACCEPTANCE_MODE -ne '1') {
        throw 'Recette refusée : RENTFLEET_ACCEPTANCE_MODE=1 est obligatoire.'
    }
}

function Assert-QaStaticGuard {
    param(
        [Parameter(Mandatory)][string]$DatabaseName,
        [Parameter(Mandatory)][string]$RunRoot,
        [string]$PostgresHost = $script:ExpectedHost,
        [int]$PostgresPort = $script:ExpectedPort,
        [string]$PostgresUser = $script:ExpectedUser
    )

    Assert-QaEnvironment

    if ($DatabaseName -ne $script:AcceptanceDatabase) {
        throw "Cible refusée : seule la base exacte '$script:AcceptanceDatabase' est autorisée."
    }
    if ($DatabaseName -in $script:ProtectedDatabases) {
        throw "Cible protégée refusée : '$DatabaseName'."
    }
    if ($PostgresHost -ne $script:ExpectedHost -or
        $PostgresPort -ne $script:ExpectedPort -or
        $PostgresUser -ne $script:ExpectedUser) {
        throw 'Serveur, port ou utilisateur PostgreSQL inattendu.'
    }

    return Resolve-QaRunRoot -RunRoot $RunRoot
}

function Assert-QaPgPassAvailable {
    $pgpass = $env:PGPASSFILE
    if ([string]::IsNullOrWhiteSpace($pgpass) -and $env:APPDATA) {
        $pgpass = Join-Path $env:APPDATA 'postgresql\pgpass.conf'
    }
    if ([string]::IsNullOrWhiteSpace($pgpass) -or
        -not (Test-Path -LiteralPath $pgpass -PathType Leaf)) {
        throw 'pgpass est absent. Aucun mot de passe ne doit être fourni en ligne de commande.'
    }
}

function Assert-QaTools {
    param([string[]]$Names)

    foreach ($name in $Names) {
        if (-not (Get-Command $name -ErrorAction SilentlyContinue)) {
            throw "Outil requis introuvable : $name"
        }
    }
}

function Invoke-QaPsqlRows {
    param(
        [Parameter(Mandatory)][string]$DatabaseName,
        [Parameter(Mandatory)][string]$Sql,
        [string]$PostgresHost = $script:ExpectedHost,
        [int]$PostgresPort = $script:ExpectedPort,
        [string]$PostgresUser = $script:ExpectedUser
    )

    $arguments = @(
        '--no-password', '--tuples-only', '--no-align', '--set=ON_ERROR_STOP=1',
        "--host=$PostgresHost", "--port=$PostgresPort", "--username=$PostgresUser",
        "--dbname=$DatabaseName", "--command=$Sql"
    )
    $output = @(& psql @arguments)
    if ($LASTEXITCODE -ne 0) {
        throw "La vérification PostgreSQL a échoué avec le code $LASTEXITCODE."
    }

    return @($output | ForEach-Object { ([string] $_).Trim() } | Where-Object { $_ -ne '' })
}

function Invoke-QaPsqlScalar {
    param(
        [Parameter(Mandatory)][string]$DatabaseName,
        [Parameter(Mandatory)][string]$Sql,
        [string]$PostgresHost = $script:ExpectedHost,
        [int]$PostgresPort = $script:ExpectedPort,
        [string]$PostgresUser = $script:ExpectedUser
    )

    $rows = @(Invoke-QaPsqlRows @PSBoundParameters)

    if ($rows.Count -eq 0) {
        return ''
    }

    return $rows[-1]
}

function Get-QaDatabaseOid {
    param(
        [Parameter(Mandatory)][string]$DatabaseName,
        [string]$PostgresHost = $script:ExpectedHost,
        [int]$PostgresPort = $script:ExpectedPort,
        [string]$PostgresUser = $script:ExpectedUser
    )

    if ($DatabaseName -notmatch '^[a-z0-9_]+$') {
        throw 'Nom de base PostgreSQL invalide.'
    }

    return Invoke-QaPsqlScalar -DatabaseName rentfleet_test `
        -Sql "select coalesce((select oid::text from pg_database where datname = '$DatabaseName'), '')" `
        -PostgresHost $PostgresHost -PostgresPort $PostgresPort -PostgresUser $PostgresUser
}

function Assert-QaTargetAbsent {
    param(
        [Parameter(Mandatory)][string]$RunRoot,
        [string]$PostgresHost = $script:ExpectedHost,
        [int]$PostgresPort = $script:ExpectedPort,
        [string]$PostgresUser = $script:ExpectedUser
    )

    Assert-QaStaticGuard -DatabaseName $script:AcceptanceDatabase -RunRoot $RunRoot `
        -PostgresHost $PostgresHost -PostgresPort $PostgresPort -PostgresUser $PostgresUser | Out-Null
    Assert-QaPgPassAvailable

    $oid = Get-QaDatabaseOid -DatabaseName $script:AcceptanceDatabase `
        -PostgresHost $PostgresHost -PostgresPort $PostgresPort -PostgresUser $PostgresUser
    if ($oid -ne '') {
        throw "La cible '$script:AcceptanceDatabase' existe déjà avec l’OID $oid."
    }
}

function Assert-QaTargetIdentity {
    param(
        [Parameter(Mandatory)][string]$RunRoot,
        [string]$PostgresHost = $script:ExpectedHost,
        [int]$PostgresPort = $script:ExpectedPort,
        [string]$PostgresUser = $script:ExpectedUser
    )

    $resolvedRunRoot = Assert-QaStaticGuard -DatabaseName $script:AcceptanceDatabase `
        -RunRoot $RunRoot -PostgresHost $PostgresHost -PostgresPort $PostgresPort `
        -PostgresUser $PostgresUser
    Assert-QaPgPassAvailable

    $identity = Invoke-QaPsqlScalar -DatabaseName $script:AcceptanceDatabase `
        -Sql "select current_database() || '|' || current_user || '|' || coalesce(host(inet_server_addr()), '') || '|' || inet_server_port()" `
        -PostgresHost $PostgresHost -PostgresPort $PostgresPort -PostgresUser $PostgresUser
    $expectedIdentity = "$script:AcceptanceDatabase|$PostgresUser|$PostgresHost|$PostgresPort"
    if ($identity -ne $expectedIdentity) {
        throw 'La connexion ne correspond pas à la cible, au serveur, au port et à l’utilisateur attendus.'
    }

    $targetOid = Get-QaDatabaseOid -DatabaseName $script:AcceptanceDatabase `
        -PostgresHost $PostgresHost -PostgresPort $PostgresPort -PostgresUser $PostgresUser
    if ($targetOid -eq '') {
        throw 'La cible QA n’existe pas.'
    }

    foreach ($protected in $script:ProtectedDatabases) {
        $protectedOid = Get-QaDatabaseOid -DatabaseName $protected `
            -PostgresHost $PostgresHost -PostgresPort $PostgresPort -PostgresUser $PostgresUser
        if ($protectedOid -ne '' -and $protectedOid -eq $targetOid) {
            throw "L’OID de la cible correspond à la base protégée '$protected'."
        }
    }

    return @{
        Database = $script:AcceptanceDatabase
        Oid = $targetOid
        RunRoot = $resolvedRunRoot
    }
}

function Get-QaTextSha256 {
    param([AllowEmptyString()][string]$Text)

    $bytes = [System.Text.Encoding]::UTF8.GetBytes($Text)
    $algorithm = [System.Security.Cryptography.SHA256]::Create()
    try {
        $hash = $algorithm.ComputeHash($bytes)
    } finally {
        $algorithm.Dispose()
    }

    return ([BitConverter]::ToString($hash) -replace '-', '').ToLowerInvariant()
}

function Get-QaCatalogSignature {
    param(
        [Parameter(Mandatory)][string]$DatabaseName,
        [Parameter(Mandatory)][string]$Sql
    )

    $rows = @(Invoke-QaPsqlRows -DatabaseName $DatabaseName -Sql $Sql)
    $text = $rows -join "`n"

    return [ordered]@{
        count = $rows.Count
        sha256 = Get-QaTextSha256 -Text $text
    }
}

function Get-QaIntegrityCounts {
    param([Parameter(Mandatory)][string]$DatabaseName)

    $queries = [ordered]@{
        custom_role_cross_tenant = "select count(*) from users u join roles r on r.id=u.role_id where r.tenant_id is not null and u.tenant_id is distinct from r.tenant_id"
        platform_role_on_tenant_user = "select count(*) from users u join roles r on r.id=u.role_id where r.slug='platform-admin' and (u.tenant_id is not null or u.agency_id is not null or u.is_platform_admin=false)"
        tenant_role_on_platform_admin = "select count(*) from users u join roles r on r.id=u.role_id where u.is_platform_admin=true and r.slug<>'platform-admin'"
        inactive_role_assigned = "select count(*) from users u join roles r on r.id=u.role_id where r.is_active=false"
        tenant_user_without_role = "select count(*) from users where is_platform_admin=false and tenant_id is not null and role_id is null"
        tenant_owner_with_agency = "select count(*) from users u join roles r on r.id=u.role_id where r.slug='tenant-owner' and u.agency_id is not null"
        agency_role_without_agency = "select count(*) from users u join roles r on r.id=u.role_id where u.is_platform_admin=false and r.slug not in ('tenant-owner','platform-admin') and u.agency_id is null"
        platform_admin_with_tenant_scope = "select count(*) from users where is_platform_admin=true and (tenant_id is not null or agency_id is not null)"
        inconsistent_delegation = "select count(*) from role_agency_delegations d left join agencies a on a.id=d.agency_id left join roles r on r.id=d.role_id left join users u on u.id=d.delegated_by where a.id is null or a.tenant_id<>d.tenant_id or a.deleted_at is not null or r.id is null or r.is_active=false or (r.tenant_id is not null and r.tenant_id<>d.tenant_id) or r.slug in ('tenant-owner','platform-admin') or (u.id is not null and (u.tenant_id<>d.tenant_id or u.is_active=false))"
    }
    $result = [ordered]@{}

    foreach ($entry in $queries.GetEnumerator()) {
        $result[$entry.Key] = [long] (Invoke-QaPsqlScalar -DatabaseName $DatabaseName -Sql $entry.Value)
    }

    return $result
}

function Get-QaDatabaseSnapshot {
    param([Parameter(Mandatory)][string]$DatabaseName)

    $catalog = [ordered]@{
        tables = Get-QaCatalogSignature -DatabaseName $DatabaseName -Sql "select schemaname||'.'||tablename from pg_tables where schemaname='public' order by 1"
        sequences = Get-QaCatalogSignature -DatabaseName $DatabaseName -Sql "select schemaname||'.'||sequencename from pg_sequences where schemaname='public' order by 1"
        functions = Get-QaCatalogSignature -DatabaseName $DatabaseName -Sql "select p.proname||'|'||pg_get_function_identity_arguments(p.oid)||'|'||md5(pg_get_functiondef(p.oid)) from pg_proc p join pg_namespace n on n.oid=p.pronamespace where n.nspname='public' order by 1"
        constraints = Get-QaCatalogSignature -DatabaseName $DatabaseName -Sql "select conname||'|'||contype::text||'|'||pg_get_constraintdef(oid) from pg_constraint where connamespace='public'::regnamespace order by 1"
        triggers = Get-QaCatalogSignature -DatabaseName $DatabaseName -Sql "select tgname||'|'||tgrelid::regclass::text||'|'||pg_get_triggerdef(oid) from pg_trigger where not tgisinternal order by 1"
        indexes = Get-QaCatalogSignature -DatabaseName $DatabaseName -Sql "select indexname||'|'||indexdef from pg_indexes where schemaname='public' order by 1"
    }

    $migrationRows = @(Invoke-QaPsqlRows -DatabaseName $DatabaseName `
        -Sql "select migration||'|'||batch from migrations order by migration")
    $batchRows = @(Invoke-QaPsqlRows -DatabaseName $DatabaseName `
        -Sql "select batch||'|'||count(*) from migrations group by batch order by batch")

    $notificationColumns = @(Invoke-QaPsqlRows -DatabaseName $DatabaseName `
        -Sql "select column_name from information_schema.columns where table_schema='public' and table_name='internal_notifications' and column_name in ('due_at','last_detected_at','resolved_at','resolution_reason','occurrence_count') order by column_name")
    $rbacTriggers = @(Invoke-QaPsqlRows -DatabaseName $DatabaseName `
        -Sql "select tgname from pg_trigger where not tgisinternal and tgname in ('users_role_assignment_integrity_guard','roles_active_assignment_guard','role_agency_delegations_scope_guard') order by tgname")
    $g2Indexes = @(Invoke-QaPsqlRows -DatabaseName $DatabaseName `
        -Sql "select indexname from pg_indexes where schemaname='public' and indexname in ('users_role_assignment_idx','internal_notifications_active_inbox_idx','internal_notifications_history_idx') order by indexname")

    return [ordered]@{
        migrations = [ordered]@{
            count = $migrationRows.Count
            sha256 = Get-QaTextSha256 -Text ($migrationRows -join "`n")
            batches = $batchRows
        }
        catalog = $catalog
        notification_lifecycle_columns = $notificationColumns
        rbac_triggers = $rbacTriggers
        g2_indexes = $g2Indexes
        integrity_counts = Get-QaIntegrityCounts -DatabaseName $DatabaseName
    }
}

function Get-QaDataSnapshot {
    param([Parameter(Mandatory)][string]$DatabaseName)

    $tables = @(Invoke-QaPsqlRows -DatabaseName $DatabaseName `
        -Sql "select tablename from pg_tables where schemaname='public' order by tablename")
    $tableRows = @()
    $tableSignatures = [ordered]@{}
    [long] $totalRows = 0

    foreach ($table in $tables) {
        if ($table -notmatch '^[a-z0-9_]+$') {
            throw 'Nom de table PostgreSQL inattendu pendant l’empreinte.'
        }

        $signature = Invoke-QaPsqlScalar -DatabaseName $DatabaseName -Sql @"
select count(*)::text || '|' ||
       md5(coalesce(string_agg(md5(to_jsonb(row_data)::text), '' order by md5(to_jsonb(row_data)::text)), ''))
from public."$table" row_data
"@
        $parts = $signature -split '\|', 2
        if ($parts.Count -ne 2) {
            throw "Empreinte de table invalide pour '$table'."
        }
        $totalRows += [long] $parts[0]
        $tableRows += "$table|$signature"
        $tableSignatures[$table] = [ordered]@{
            rows = [long] $parts[0]
            content_md5 = $parts[1]
        }
    }

    $sequenceRows = @(Invoke-QaPsqlRows -DatabaseName $DatabaseName -Sql @"
select sequencename||'|'||coalesce(last_value::text,'')||'|'||start_value::text
    ||'|'||increment_by::text||'|'||min_value::text||'|'||max_value::text||'|'||cycle::text
from pg_sequences
where schemaname='public'
order by sequencename
"@)

    return [ordered]@{
        tables = $tables.Count
        rows = $totalRows
        table_signatures = $tableSignatures
        content_sha256 = Get-QaTextSha256 -Text ($tableRows -join "`n")
        sequences = $sequenceRows.Count
        sequences_sha256 = Get-QaTextSha256 -Text ($sequenceRows -join "`n")
    }
}

function Get-QaPortableCatalogSnapshot {
    param([Parameter(Mandatory)][string]$DatabaseName)

    # pg_dump/pg_restore may render equivalent ANY(array) expressions with
    # different casts. Compare stable catalog attributes instead of deparsed
    # CHECK constraints and partial-index predicates.
    return [ordered]@{
        constraints = Get-QaCatalogSignature -DatabaseName $DatabaseName -Sql @"
select n.nspname||'.'||r.relname||'|'||c.conname||'|'||c.contype::text
    ||'|'||c.convalidated::text||'|'||c.condeferrable::text||'|'||c.condeferred::text
    ||'|'||coalesce(c.conkey::text,'')
    ||'|'||case when c.confrelid=0 then '' else c.confrelid::regclass::text end
    ||'|'||coalesce(c.confkey::text,'')
    ||'|'||c.confupdtype::text||'|'||c.confdeltype::text||'|'||c.confmatchtype::text
from pg_constraint c
join pg_class r on r.oid=c.conrelid
join pg_namespace n on n.oid=r.relnamespace
where n.nspname='public'
order by 1
"@
        indexes = Get-QaCatalogSignature -DatabaseName $DatabaseName -Sql @"
select n.nspname||'.'||t.relname||'|'||idx.relname||'|'||am.amname
    ||'|'||i.indisunique::text||'|'||i.indisprimary::text
    ||'|'||i.indisexclusion::text||'|'||i.indimmediate::text
    ||'|'||i.indisvalid::text||'|'||i.indisready::text||'|'||i.indislive::text
    ||'|'||i.indkey::text||'|'||i.indoption::text
from pg_index i
join pg_class idx on idx.oid=i.indexrelid
join pg_class t on t.oid=i.indrelid
join pg_namespace n on n.oid=t.relnamespace
join pg_am am on am.oid=idx.relam
where n.nspname='public'
order by 1
"@
    }
}

function ConvertTo-QaComparableRestoreSnapshot {
    param(
        [Parameter(Mandatory)]$Snapshot,
        [Parameter(Mandatory)]$PortableCatalog
    )

    return [ordered]@{
        migrations = $Snapshot.migrations
        catalog = [ordered]@{
            tables = $Snapshot.catalog.tables
            sequences = $Snapshot.catalog.sequences
            functions = $Snapshot.catalog.functions
            constraints = $PortableCatalog.constraints
            triggers = $Snapshot.catalog.triggers
            indexes = $PortableCatalog.indexes
        }
        notification_lifecycle_columns = $Snapshot.notification_lifecycle_columns
        rbac_triggers = $Snapshot.rbac_triggers
        g2_indexes = $Snapshot.g2_indexes
        integrity_counts = $Snapshot.integrity_counts
    }
}

function Assert-QaSnapshotEqual {
    param(
        [Parameter(Mandatory)]$Expected,
        [Parameter(Mandatory)]$Actual
    )

    $expectedJson = $Expected | ConvertTo-Json -Depth 20 -Compress
    $actualJson = $Actual | ConvertTo-Json -Depth 20 -Compress
    if ($expectedJson -ne $actualJson) {
        throw 'L’empreinte structurelle ou les compteurs d’intégrité diffèrent de la source.'
    }
}
