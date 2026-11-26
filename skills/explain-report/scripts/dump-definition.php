<?php

/**
 * @file dump-definition.php
 *
 * @brief Read-only JSON dump of one report definition, as the engine resolves it.
 *
 * @details The data source for /explain-report. It boots Laravel, points the
 * `tenant` connection at one tenant, resolves the definition through the real
 * ReportRegistry (so profile-field columns and schema probes are attached the
 * way a request sees them) and writes every fact the six answers need: pinned
 * and default columns, every column's SQL, operators and export shape, every
 * join's SQL, the export date range with its presets resolved, the export and
 * request limits, and three root-level row counts that show what the defaults
 * cut away.
 *
 * It writes no database row. The only statements it runs are the schema probes
 * the registry always runs, the profile-field read, one read of the role
 * templates that grant the report's permission slot, and up to three COUNTs.
 *
 * Usage (from inside a layup-reports checkout, or with --root):
 *
 *   "C:/wamp64/bin/php/php8.4.15/php.exe" <skill dir>/scripts/dump-definition.php
 *       <report-key> --out=<file.json> [--tenant=playground] [--static] [--no-counts] [--context=<id>]
 *       [--root=<path to layup-reports>]
 *
 * --root          the layup-reports checkout to read; default: the current directory or a parent
 * --static        no tenant and no profile-field columns (the committed-docs view)
 * --no-counts     skip the three COUNT statements
 * --context=<id>  open the report for one parent, as a drill-down is opened: every
 *                 counted body carries a filter on the context column (or the first
 *                 required filter) equal to this id
 */

use App\Http\Controllers\ReportControllers\ReportFilterOptionController;
use App\Jobs\GenerateReportExport;
use App\Providers\AppServiceProvider;
use App\Reports\ColumnDefinition;
use App\Reports\JoinDefinition;
use App\Services\Reports\ReportDateRangeService;
use App\Services\Reports\ReportExportQueueService;
use App\Services\Reports\ReportExportService;
use App\Services\Reports\ReportRegistry;
use App\Services\Reports\ReportRequestParser;
use App\Services\Reports\ReportRunner;
use App\Services\Reports\ReportSqlCompiler;
use App\Services\Tenancy\TenantConnection;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// The skill is installed outside the repository it reads (`npx skills add`
// puts it in `.agents/skills/`, `.claude/skills/` or `~/.claude/skills/`), so
// the layup-reports checkout is found rather than assumed: `--root=<dir>`,
// else the current directory or a parent of it, else a parent of this script.
$isProjectRoot = fn (string $dir): bool => is_file($dir.'/artisan')
    && is_file($dir.'/app/Reports/ReportDefinitionInterface.php');

$root = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--root=')) {
        $root = rtrim(substr($argument, 7), '/\\');

        if (! $isProjectRoot($root)) {
            fwrite(STDERR, "--root=$root is not a layup-reports checkout (no artisan or app/Reports).\n");
            exit(2);
        }
    }
}

foreach ($root === null ? [getcwd(), __DIR__] : [] as $start) {
    for ($dir = $start; is_string($dir) && $dir !== dirname($dir); $dir = dirname($dir)) {
        if ($isProjectRoot($dir)) {
            $root = $dir;

            break 2;
        }
    }
}

if ($root === null) {
    fwrite(STDERR, "Run this from inside a layup-reports checkout, or pass --root=<path to layup-reports>.\n");
    exit(2);
}

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// ---------------------------------------------------------------- arguments

$reportKey = null;
$tenant = 'playground';
$static = false;
$withCounts = true;
$outPath = null;
$contextId = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--context=')) {
        $contextId = substr($argument, 10);
    } elseif (str_starts_with($argument, '--tenant=')) {
        $tenant = substr($argument, 9);
    } elseif (str_starts_with($argument, '--out=')) {
        $outPath = substr($argument, 6);
    } elseif ($argument === '--static') {
        $static = true;
    } elseif ($argument === '--no-counts') {
        $withCounts = false;
    } elseif (! str_starts_with($argument, '--')) {
        $reportKey = $argument;
    }
}

if ($reportKey === null || $outPath === null) {
    fwrite(STDERR, "Usage: php dump-definition.php <report-key> --out=<file.json> [--tenant=playground] [--static] [--no-counts]\n");
    exit(2);
}

$registered = (new ReflectionClass(AppServiceProvider::class))->getConstant('REPORT_DEFINITIONS');

if (! isset($registered[$reportKey])) {
    $close = array_values(array_filter(
        array_keys($registered),
        fn (string $key) => levenshtein($key, $reportKey) <= 4 || str_contains($key, $reportKey)
    ));

    fwrite(STDERR, "Unknown report key '$reportKey'.\n");
    fwrite(STDERR, $close === []
        ? 'Registered keys: '.implode(', ', array_keys($registered))."\n"
        : 'Did you mean: '.implode(', ', $close)."\n");
    exit(1);
}

$registry = app(ReportRegistry::class);

if ($static) {
    $registry->withoutDynamicColumns();
    $tenant = null;
    $withCounts = false;
} else {
    if (! TenantConnection::isAllowed($tenant)) {
        fwrite(STDERR, "Tenant '$tenant' is not in TENANT_DATABASES. Pass --tenant=<one that is>, or --static.\n");
        exit(1);
    }

    TenantConnection::activate($tenant);
}

$definition = $registry->get($reportKey);

// ---------------------------------------------------------------- helpers

/**
 * @brief Read a constant whatever its visibility.
 */
$constant = fn (string $class, string $name) => (new ReflectionClass($class))->getConstant($name);

$joins = $definition->joins();

/**
 * @brief Every join a list of join keys needs, following dependsOn.
 *
 * @return string[]
 */
$joinClosure = function (array $joinKeys) use ($joins): array {
    $seen = [];
    $stack = $joinKeys;

    while ($stack !== []) {
        $joinKey = array_pop($stack);

        if (isset($seen[$joinKey])) {
            continue;
        }

        $seen[$joinKey] = true;

        foreach ($joins[$joinKey]->dependsOn ?? [] as $dependency) {
            $stack[] = $dependency;
        }
    }

    return array_keys($seen);
};

$pinned = $definition->pinnedColumns();
$defaults = $definition->defaultColumns();
$dynamicKeys = $definition->dynamicColumnKeys();
$enrichers = $definition->enrichers();

/**
 * @brief The CSV header cells one column becomes in an export.
 *
 * @return string[]
 */
$csvHeaders = function (ColumnDefinition $column) use ($enrichers): array {
    $enricherClass = $enrichers[$column->key] ?? null;

    if ($enricherClass === null) {
        return [$column->exportAsMinutes ? $column->label.' (Minutes)' : $column->label];
    }

    try {
        return array_values(app($enricherClass)->csvColumns($column->key, $column->label));
    } catch (Throwable $e) {
        return ['(could not read the enricher\'s CSV columns: '.get_class($e).')'];
    }
};

// ---------------------------------------------------------------- columns

$columns = [];
$joinUsers = [];

foreach ($definition->columns() as $column) {
    $windowed = false;

    foreach ($column->requiresJoins as $joinKey) {
        if (($joins[$joinKey] ?? null)?->isWindowed()) {
            $windowed = true;

            break;
        }
    }

    foreach ($column->requiresJoins as $joinKey) {
        $joinUsers[$joinKey][] = $column->key;
    }

    $defaultPosition = array_search($column->key, $defaults, true);

    $columns[] = [
        'key' => $column->key,
        'label' => $column->label,
        'type' => $column->type->value,
        'format' => $column->format ?? $column->type->value,
        'tooltip' => $column->tooltip,
        'pinned' => in_array($column->key, $pinned, true),
        'default' => $defaultPosition !== false,
        'default_position' => $defaultPosition === false ? null : $defaultPosition + 1,
        'selectable' => $column->selectable,
        'sortable' => $column->sortable,
        'filterable' => $column->filterable,
        'operators' => $column->filterable ? $column->availableOperators() : [],
        'dropdown' => $column->usesLookupOptions(),
        'lookup_source' => $column->lookupSource,
        'lookup_from' => $column->lookupFrom,
        'options' => $column->enumOptions,
        'nullable' => $column->nullable,
        'dynamic_profile_field' => in_array($column->key, $dynamicKeys, true),
        'select_expression' => $column->selectExpression,
        'filter_expression' => $column->filterExpression,
        'sort_expression' => $column->sortExpression,
        'filter_in_having' => $column->filterInHaving,
        'membership_predicate' => $column->membershipPredicate,
        'exists_predicate' => $column->existsPredicate,
        'exact_match' => $column->exactMatch,
        'max_filter_values' => $column->maxFilterValues,
        'row_key_prefix' => $column->rowKeyPrefix,
        'requires_joins' => $column->requiresJoins,
        'joins_needed' => $joinClosure($column->requiresJoins),
        'window_total' => $windowed,
        'enricher' => $enrichers[$column->key] ?? null,
        'export' => [
            'csv_headers' => $csvHeaders($column),
            'as_minutes' => $column->exportAsMinutes,
            'enum_labels' => $column->exportEnumLabels,
        ],
    ];
}

// ---------------------------------------------------------------- joins

$joinDump = [];
$sharedCtes = [];

foreach ($joins as $joinKey => $join) {
    /** @var JoinDefinition $join */
    foreach ($join->sharedCtes as $cte) {
        $sharedCtes[$cte->key] = ['sql' => $cte->sql, 'has_scoped_variant' => $cte->scopedSql !== null];
    }

    $joinDump[$joinKey] = [
        'alias' => $join->alias,
        'depends_on' => $join->dependsOn,
        'sql' => $join->sql,
        'has_scoped_variant' => $join->scopedSql !== null,
        'expanded_sql' => $join->expandedSql,
        'window_total' => $join->isWindowed(),
        'reads_window' => $join->readsWindow(),
        'shared_ctes' => array_map(fn ($cte) => $cte->key, $join->sharedCtes),
        'used_by_columns' => $joinUsers[$joinKey] ?? [],
    ];
}

// ---------------------------------------------------------------- export

$dateRanges = app(ReportDateRangeService::class);

try {
    $dateRangeDescription = $dateRanges->describe($definition);
} catch (Throwable $e) {
    $dateRangeDescription = ['error' => get_class($e).': '.$e->getMessage()];
}

$defaultLayoutHeaders = [];
$definitionColumns = $definition->columns();

foreach (array_values(array_unique(array_merge($pinned, $defaults))) as $key) {
    if (isset($definitionColumns[$key])) {
        array_push($defaultLayoutHeaders, ...$csvHeaders($definitionColumns[$key]));
    }
}

$permissionReports = config('report_permissions.reports', []);
$slot = $permissionReports[$reportKey] ?? null;

// Who holds the slot on this tenant: live templates whose stored matrix names
// it, with the actions each grants. Read-only, and quoted per segment because a
// section key may carry hyphens (`ai-token-report`).
$slotHolders = null;

if ($tenant !== null && $slot !== null) {
    try {
        $path = '$."'.$slot[0].'"."'.$slot[1].'"';

        $slotHolders = array_map(fn ($row) => [
            // No template_name: it is free text, and a tenant's can name a customer.
            'template_id' => (int) $row->template_id,
            'role_type' => $row->role_type,
            'actions' => json_decode((string) $row->actions, true),
        ], DB::select(
            'SELECT p.template_id, t.role_type, JSON_EXTRACT(p.permissions, ?) AS actions '
            .'FROM admin_role_configuration_permission p '
            .'JOIN admin_role_configuration t ON t.id = p.template_id '
            ."WHERE COALESCE(t.is_delete, '0') = '0' AND JSON_EXTRACT(p.permissions, ?) IS NOT NULL "
            .'ORDER BY t.id',
            [$path, $path]
        ));
    } catch (Throwable $e) {
        $slotHolders = ['error' => get_class($e).': '.$e->getMessage()];
    }
}

$export = [
    'permission_slot' => $slot,
    'slot_holders' => $slotHolders,
    'open_report' => in_array($reportKey, config('report_permissions.open_reports', []), true),
    'actions' => config('report_permissions.actions'),
    'date_range_declaration' => $definition->exportDateRange(),
    'date_range' => $dateRangeDescription,
    'sync_row_limit_this_machine' => (int) config('report_exports.sync_row_limit'),
    'sync_row_limit_for_report' => app(ReportExportQueueService::class)->syncRowLimitFor($definition),
    'report_lowers_sync_limit_to' => $definition->syncExportRowLimit(),
    'max_export_rows' => ReportRequestParser::MAX_EXPORT_ROWS,
    'max_selected_ids' => ReportRequestParser::MAX_SELECTED_IDS,
    'batch_size' => $constant(ReportExportService::class, 'BATCH_SIZE'),
    'export_statement_timeout_ms' => $constant(ReportSqlCompiler::class, 'EXPORT_QUERY_TIMEOUT_MS'),
    'disk' => config('report_exports.disk'),
    'retention_days' => (int) config('report_exports.retention_days'),
    'download_url_ttl_minutes' => (int) config('report_exports.download_url_ttl_minutes'),
    'stale_processing_hours' => (int) config('report_exports.stale_processing_hours'),
    'queued_job_timeout_seconds' => (new ReflectionClass(GenerateReportExport::class))->getProperty('timeout')->getDefaultValue(),
    'queued_job_tries' => (new ReflectionClass(GenerateReportExport::class))->getProperty('tries')->getDefaultValue(),
    'default_layout_csv_headers' => $defaultLayoutHeaders,
];

// ---------------------------------------------------------------- limits

$limits = [
    'max_page_size' => ReportRequestParser::MAX_PAGE_SIZE,
    'default_page_size' => ReportRequestParser::DEFAULT_PAGE_SIZE,
    'max_page' => ReportRequestParser::MAX_PAGE,
    'max_filters' => ReportRequestParser::MAX_FILTERS,
    'max_sort_columns' => ReportRequestParser::MAX_SORT_COLUMNS,
    'max_filter_values' => ReportRequestParser::MAX_FILTER_VALUES,
    'max_value_length' => ReportRequestParser::MAX_VALUE_LENGTH,
    'max_selected_ids' => ReportRequestParser::MAX_SELECTED_IDS,
    'default_window_days' => ReportRequestParser::DEFAULT_WINDOW_DAYS,
    'max_window_days' => ReportRequestParser::MAX_WINDOW_DAYS,
    'default_window_master_days' => ReportRequestParser::DEFAULT_WINDOW_MASTER_DAYS,
    'max_window_master_days' => ReportRequestParser::MAX_WINDOW_MASTER_DAYS,
    'data_statement_timeout_ms' => $constant(ReportSqlCompiler::class, 'DATA_QUERY_TIMEOUT_MS'),
    'filter_options_page_size' => $constant(ReportFilterOptionController::class, 'PAGE_SIZE'),
    'api_rate_limit' => '1,000 requests a minute per user and tenant: RateLimiter::for(\'api\') in AppServiceProvider (not read at runtime; re-check there)',
];

// ---------------------------------------------------------------- counts

$counts = null;

if ($withCounts) {
    $parser = app(ReportRequestParser::class);
    $runner = app(ReportRunner::class);

    $presetKeys = array_column($dateRangeDescription['presets'] ?? [], 'key');

    $contextColumn = $definition->contextFilter()['column'] ?? ($definition->requiredFilters()[0] ?? null);
    $contextFilters = [];

    if ($contextId !== null && $contextColumn !== null) {
        $contextFilters = [['column' => $contextColumn, 'value' => ctype_digit($contextId) ? (int) $contextId : $contextId]];
    }

    // With a context id there is no body-less request to show: a drill-down is
    // always opened by sending that one filter, and sending any `filters` array
    // displaces defaultFilters() — which is empty on every context report anyway.
    $bodies = $contextFilters === []
        ? ['body_less' => [], 'no_filters' => ['filters' => []]]
        : ['context_only' => ['filters' => $contextFilters]];

    if (in_array('all_time', $presetKeys, true)) {
        $bodies[$contextFilters === [] ? 'no_filters_all_time' : 'context_all_time']
            = ['filters' => $contextFilters, 'date_range' => ['preset' => 'all_time']];
    }

    $counts = [
        'note' => 'Root caller, no saved layout, no row scope. A scoped caller sees a subset.',
        'context' => $contextFilters === [] ? null : $contextFilters[0],
        'context_column_available' => $contextColumn,
    ];

    foreach ($bodies as $name => $body) {
        try {
            $query = $parser->parse($definition, $body);

            $counts[$name] = [
                'body' => $body,
                'rows' => $runner->count($definition, $query),
                'filters' => array_map(fn ($filter) => $filter->toArray(), $query->filters),
                'date_range' => $query->dateRange?->toArray(),
                'window' => $query->window(),
                'warnings' => $query->warnings,
            ];
        } catch (Throwable $e) {
            $counts[$name] = ['body' => $body, 'error' => get_class($e).': '.$e->getMessage()];
        }
    }
}

// ---------------------------------------------------------------- report

$class = new ReflectionClass($definition);

$dump = [
    'report' => [
        'key' => $definition->reportKey(),
        'title' => $definition->title(),
        'group' => $definition->group(),
        'row_label' => $definition->rowLabel(),
        'class' => $class->getName(),
        'file' => str_replace('\\', '/', substr((string) realpath((string) $class->getFileName()), strlen((string) realpath($root)) + 1)),
        'tenant' => $tenant,
        'base_table' => $definition->baseTable(),
        'base_table_reads_window' => str_contains($definition->baseTable(), '{{DATE_FROM}}'),
        'row_key' => $definition->rowKeyExpression(),
        'base_conditions' => $definition->baseConditions(),
        'pinned_columns' => $pinned,
        'default_columns' => $defaults,
        'default_sort' => $definition->defaultSort(),
        'default_filters' => $definition->defaultFilters(),
        'implicit_filters' => $definition->implicitFilters(),
        'required_filters' => $definition->requiredFilters(),
        'searchable_columns' => $definition->searchableColumns(),
        'context_filter' => $definition->contextFilter(),
        'date_range_column' => $definition->dateRangeColumn(),
        'max_date_range_days' => $definition->maxDateRangeDays(),
        'uses_reporting_window' => $definition->usesReportingWindow(),
        'window_month_column' => $definition->windowMonthColumn(),
        'whole_month_date_columns' => $definition->wholeMonthDateColumns(),
        'scope_descriptor' => $definition->scopeDescriptor(),
        'stats_descriptor' => $definition->statsDescriptor(),
        'expansion_descriptor' => $definition->expansionDescriptor(),
        'allows_pca_instances' => $definition->allowsPcaInstances(),
        'available_for_tenants' => $definition->availableForTenants(),
        'schema_probes' => $definition->schemaProbes(),
        'required_indexes' => $definition->requiredIndexes(),
        'enrichers' => $enrichers,
        'column_counts' => [
            'all' => count($columns),
            'selectable' => count(array_filter($columns, fn ($c) => $c['selectable'])),
            'internal_handles' => count(array_filter($columns, fn ($c) => ! $c['selectable'])),
            'filterable' => count(array_filter($columns, fn ($c) => $c['filterable'])),
            'sortable' => count(array_filter($columns, fn ($c) => $c['sortable'])),
            'profile_fields' => count($dynamicKeys),
            'window_totals' => count(array_filter($columns, fn ($c) => $c['window_total'])),
        ],
    ],
    'columns' => $columns,
    'joins' => $joinDump,
    'shared_ctes' => $sharedCtes,
    'export' => $export,
    'limits' => $limits,
    'counts' => $counts,
];

$json = json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

if ($json === false || file_put_contents($outPath, $json) === false) {
    fwrite(STDERR, "Could not write $outPath\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Wrote %s: %d columns (%d selectable, %d filterable), %d joins, tenant %s.\n",
    $outPath,
    $dump['report']['column_counts']['all'],
    $dump['report']['column_counts']['selectable'],
    $dump['report']['column_counts']['filterable'],
    count($joinDump),
    $tenant ?? '(static)'
));
