---
name: explain-report
description: Use when the user runs /explain-report <report-key>, or asks to explain an existing layup-reports report - its pinned column, default columns, the column list and the filters on each column, how each column's value is calculated, how the report's download/export works, or what limits and cut-offs narrow its data. Read-only; for building a new report use /layup-default-report.
argument-hint: "<report-key>"
---

# /explain-report — explain one report, in six fixed sections

The argument is a report key from `AppServiceProvider::REPORT_DEFINITIONS`, e.g.
`/explain-report course-report`. You answer six questions about it, in the order and shape given in
§3, and every fact in the answer comes from one of three places:

| Fact | Source |
|---|---|
| what the engine does (columns, SQL, operators, defaults, limits) | the dump from `scripts/dump-definition.php` |
| why it does it | the definition class's docblocks, and the shared classes its joins come from |
| what the data really means | `CLAUDE.md`'s "Never guess at the schema" table, and read-only SQL on playground |

It works only inside a **layup-reports** checkout, because the dump loads that repository's code and
the caveats come from its `CLAUDE.md`. If the current project is not layup-reports, say so and stop.

This is **read-only**. Edit no file, write no database row, start no server, and do not run
`report:verify-api`. The dump script runs only the registry's schema probes, the profile-field read,
one read of the role templates granting the report's slot, and up to three `COUNT`s.

When a docblock and the dump disagree, **the dump is right** and you say so in one line. Comments in
this repo lag the code: `ReportDataController::reportCount()` still says only `/export` defaults a
date range, while the parser has done it on every endpoint since 2026-09-23, and
`ReportQuery::withPage()` implies an expanded export carries child rows, which
`ReportExportService` never fetches.

## 1. Get the facts

1. **No argument:** ask for the report key and nothing else.
2. Run the dump from the layup-reports root (Git Bash), writing into this session's scratchpad.
   The script sits beside this file, in `scripts/`: use this skill's base directory as Claude Code
   reports it when the skill loads (`.claude/skills/explain-report` for a project install,
   `~/.claude/skills/explain-report` for a global one).

   ```bash
   "C:/wamp64/bin/php/php8.4.15/php.exe" "<skill dir>/scripts/dump-definition.php" \
       <report-key> --out="<scratchpad>/explain-<report-key>.json"
   ```

   - The PHP and `mysql` paths here are the team's WAMP defaults, the same ones `CLAUDE.md` uses.
     If PHP 8.4 or the `mysql` client lives somewhere else on this machine, use that binary.
   - The script finds the layup-reports checkout from the current directory. Run from anywhere
     else and it exits 2 asking for `--root=<path to layup-reports>`.
   - Your local `TENANT_DATABASES` must list the tenant you read.
   - An unknown key exits 1 and prints the near matches. Ask which one was meant.
   - `--tenant=<name>` reads another tenant in `TENANT_DATABASES`; the default is `playground`.
   - If the database is unreachable, rerun with `--static`. That drops the profile-field columns and
     the counts, so say so in the answer's header.
   - **A drill-down report** (one with `context_filter` or `required_filters`) is opened for one
     parent, so its body-less count is a 422 or the whole tenant. Pick one real parent id with a
     read-only `SELECT` (one with plenty of rows) and rerun with `--context=<id>`. Say which id.
     On `active-users-report` and `signup-users-report` the context is a day: `--context=2026-06-22`.
   - **A count that failed with `1146` or `1054`** means this tenant lacks a table or column the
     report reads, so the report itself answers 500 here. That is a finding: name the missing
     identifier in §6 and check `CLAUDE.md`'s schema table for which tenants have it.
3. Read the JSON. Its blocks: `report` (every declaration), `columns` (one entry per column, with
   its SQL, operators, export headers and the joins it needs), `joins` (full SQL, which columns use
   each), `shared_ctes`, `export`, `limits`, `counts` (root-level row counts for a body-less request,
   for `filters: []`, and for `filters: []` plus All Time; with `--context`, for the context filter
   alone and with All Time).
4. Open `report.file`. Read its class docblock and the docblock of every method the dump reflects
   (`baseConditions`, `defaultFilters`, `pinnedColumns`, `exportDateRange`, ...). Then open every class
   its joins and columns come from: the `App\Reports\Shared\*` classes it imports, and each enricher
   in `report.enrichers`. The SQL in the dump is the truth; these files carry the reasons.
5. For every table the joins read, scan `CLAUDE.md`'s schema table for its row. A row that names the
   table is a caveat on the columns that read it, and goes into §4 of the answer.
6. Measure with a read-only `SELECT` on playground for two reasons only: to settle what a column
   means when the SQL and docblocks leave it ambiguous, and to give a Caveat line its one number.
   That is at most one query per column; batch several into one `mysql` call. Do not hunt outliers
   or profile distributions beyond that. If you cannot settle a meaning, write "not confirmed".
   Never guess at the schema.

   ```bash
   "C:/wamp64/bin/mysql/mysql8.4.7/bin/mysql.exe" -u root --protocol=TCP -h 127.0.0.1 playground -N -e 'SELECT ...; SELECT ...;'
   ```

   Wrap the SQL in **single** quotes: `groups` and `over` are reserved words that need backticks,
   and inside double quotes Git Bash runs backticks as commands. Use `"` for SQL string literals.

## 2. How to read the dump

| Field | Means |
|---|---|
| `pinned` | always returned and cannot be deselected; prepended to every layout |
| `default`, `default_position` | the first screen for a caller with **no saved layout**; a saved layout replaces it |
| `selectable: false` | an internal handle: usable in `filters` and `sort`, never shown in the column picker |
| `filterable`, `operators` | the operators a filter on this column accepts; `[]` means it cannot be filtered |
| `dropdown` + `lookup_source` | `static`: the values are `options`. `distinct`: the dropdown lists the distinct values in the report's rows (or in `lookup_from`, the scoped catalogue, when set). No dropdown: free text, number or date |
| `filter_expression`, `sort_expression` | the column filters or sorts on something other than what it shows (e.g. the raw date behind a converted one) |
| `membership_predicate` | `in` / `not_in` mean "belongs to one of these ids"; the number operators still compare the count |
| `exists_predicate` | every filter reads "has at least one related row where ..." |
| `exact_match: false` on a `string` or `lookup` | `equals` / `in` trim spaces and undo HTML entities on both sides (`FilterTextNormaliser`); `contains` and `starts_with` do not |
| `window_total: true` | the value is a total over the reporting window, `date_from`..`date_to` (180 days by default) |
| `enricher` | SQL returns a count; after paging the enricher replaces it with an object such as `{count, groups}`; the CSV splits it into `export.csv_headers`. Read the enricher: when it narrows to the caller's visible items, a scoped caller's displayed count can be lower than the count the sort and filter compare |
| `export.as_minutes`, `export.enum_labels` | the CSV writes minutes instead of seconds, or the option label instead of the stored value |
| `joins_needed` | every join the column pulls in, following `depends_on` |
| `joins.*.expanded_sql` | the SQL a join switches to when the expansion mode (`non_consolidated` on the course report) is sent; null when it does not switch |
| `export.slot_holders` | the live role templates on this tenant that grant the report's slot, and with which actions; root (level 1) needs none |
| `default_sort` | the compiler always appends `report.row_key` ascending as the last sort key, so equal values keep a stable order |
| `counts.*.rows` | how many rows a root caller gets for that body; the differences are what the defaults cut |

Six things the field names do not tell you:

- **Numbers in docblocks are old.** A comment's "372 rows" was measured once; the dump's `counts`
  are today's. Quote the dump, and the comment's figure only as history.
- **A tooltip or docblock can contradict the SQL.** Compare each `tooltip` against `operators`
  and each docblock against `select_expression`, and list every contradiction in the answer.

- **A key's name does not describe its value.** Read the SQL. Keys carried over from v3 are kept
  even where they are wrong (`username` is `u.name`; `users_userPublished` inverts `u.unpublished`).
- **The reporting window narrows nothing unless something reads it.** Every request echoes a window,
  but it changes a number only when `report.column_counts.window_totals > 0`, a join has
  `reads_window: true`, or `report.base_table_reads_window` is true. Otherwise say it has no effect.
- **The sync export limit is not a cut-off.** Above it the export is queued, not truncated. The only
  hard ceiling is `max_export_rows`, and above it the export is refused with a 422.
- **`sync_row_limit_this_machine` is this machine's `.env`.** Production is set by
  `REPORT_EXPORT_SYNC_ROW_LIMIT`; `config/report_exports.php` recommends 4,000. Report both.

## 3. The answer — this shape, these headings, in this order

Write it in chat. Use the report's labels for people and the keys in backticks for developers.

**Header (3–6 lines):** `# <title> (`<key>`)`, then: what one row is (`row_label`, `base_table`, and
`base_conditions` in plain words), who may open it (`export.permission_slot`, `view` to read and
`download` to export, and from `export.slot_holders` which template ids grant each — by id, never
by template name), the row scope (`scope_descriptor` in plain words), the `/stats` summary card if
`stats_descriptor` is set, the tenant the dump read, and a link to the definition file.

### 1. Pinned column

A table, Label | Key | Why it is pinned (from the docblock). If `pinned_columns` is empty, write
"None: every column can be deselected." Add one line: every row also carries `id`, the row key
(`report.row_key`), which is not a column.

### 2. Default columns

A numbered list in `default_position` order: Label (`key`). Then three lines: the default sort, the
default filters with the rows they keep (`counts.body_less.rows` against `counts.no_filters.rows`),
and that a saved layout replaces all of this.

### 3. Columns and the filters on each

One table of every selectable column, in the order the dump lists them:

| # | Label | Key | Type | Default | Sortable | Filter operators | Filter values |
|---|---|---|---|---|---|---|---|

"Filter values" says what a user picks from: the static option list, a dropdown of distinct values
(and from where), free text, a number, or a date. Then, only where they apply:

- **Filter-only handles** (`selectable: false`): a second small table, with what each is for.
- **Profile fields** (`dynamic_profile_field`): one line saying how many the tenant offers, their
  labels, and that the keys are `users_extraField_{id}` and differ between tenants.
- **Search:** the `searchable_columns`, OR-ed together.
- **Filter behaviours a user would not guess:** the `membership_predicate`, `exists_predicate`,
  `non_consolidated`, trim-and-decode equality and `lookup_from` widening, each in one line on the
  column it applies to.

### 4. Each column's logic

Start with **Shared sources**: one short paragraph per join that is more than a one-table lookup,
whether one column reads it or several, saying which tables it reads, how it joins to the row, what
it aggregates, and what its `expanded_sql` changes. Then one subsection per
column, `#### <n>. <Label> (`<key>`)`, in the table's order, each with these lines and no others:

- **Value:** what it means, in one sentence a report user would understand.
- **Source:** tables and columns, the join condition, and the conditions that select rows
  (`status = 1`, `is_deleted = 0`, ...). Name a shared source instead of repeating it.
- **Calculation:** the formula, e.g. `completed users ÷ participants × 100, 1 decimal`.
- **When there is no data:** `0` (a `COALESCE`) or blank (a `NULLIF`, or a nullable column).
- **Filter and sort:** only when `filter_expression` or `sort_expression` differs, or it is a window
  total.
- **Caveat:** only a `CLAUDE.md` schema row, or a measured fact, that changes how to read the number.
  Quote the number it gives.

Every column gets its own subsection, including the short ones. Profile fields share one subsection,
because they are one pattern.

### 5. Download (export) logic

Cover each point, with this report's values:

1. **Request and permission:** `POST /{tenant}/v1/report/<key>/export`, needs `download` on the slot;
   a template granting `view` alone cannot export.
2. **What the file holds:** the screen's columns (request, then saved layout, then defaults, pinned
   first), filters, sort, search and row scope; the CSV header row for the default layout
   (`export.default_layout_csv_headers`); columns that split, convert to minutes or write labels;
   HTML entities decoded; a cell opening with `= + - @` is prefixed with `'` (negative numbers
   exempt); booleans written `true` / `false`; `id` is not in the file. If `expansion_descriptor`
   is set, the child rows (`childrenKey`) are **not** in the file, and say what the parent rows
   hold when the expansion mode is sent anyway (read the joins' `expanded_sql`).
3. **Date range picker:** `export.date_range` — the column, every preset with today's dates, and
   `applies_by_default`. When it is false, say a request naming no range gets none. When it is true,
   say a request that states no period gets the Default preset on every endpoint. When it is null,
   there is no picker and `date_range` is a 422.
4. **Direct or queued:** at or below `sync_row_limit_for_report` rows the CSV streams back (`200`,
   `X-Report-Export-Mode: direct`). Above it, `202` and a queued file on `export.disk`, polled by
   posting `{"export_id": N}` to the same route or through `/report/exports`, downloaded through a
   presigned URL valid `download_url_ttl_minutes` minutes, kept `retention_days` days. The worker
   runs `queued_job_tries` time(s) for at most `queued_job_timeout_seconds`, re-checks `download`,
   and a job stuck in `processing` past `stale_processing_hours` is marked failed. Above
   `max_export_rows`, a 422 naming the count, never a truncated file. `POST /count` answers
   `export_mode` and `export_row_limit` before the click. `POST /export-async` takes the same body
   and always queues.
5. **Selection:** `ids` exports only the ticked rows, at most `max_selected_ids`. A selection does
   **not** inherit the saved layout's or the report's default filters (only filters sent with it),
   while the base conditions and the row scope still apply.
6. **Instances:** whether a PCA child may be exported (`allows_pca_instances`), and the
   `PCA_<instance>_` file name prefix when it may.
7. **File name:** `<key>-<Ymd-His>.csv` when direct, `<key>-<Ymd-His>-<export id>.csv` when queued.

### 6. Data cut-offs and limits

Three parts, each a short table or list:

1. **Rows the report never shows** (no caller can turn these off): each base condition in plain
   words; deleted users (`LiveUser` in the joins); and, for a non-root caller, the row scope. Use a
   measured number where you have one.
2. **Defaults that narrow, which a caller can change:** default filters, the default date range,
   implicit filters, required filters, the reporting window on window-total columns, and
   `max_date_range_days`. Put the `counts` beside them as a table (body, rows, what it shows).
3. **Hard limits** from `limits` and `export`: page size, pages, filters per request, values per
   `in` list (and any column's `max_filter_values`), value length, sort keys, `ids`, export rows,
   and the statement timeouts (`data_statement_timeout_ms` on screen, `export_statement_timeout_ms`
   for an export). A statement past its timeout is a 504. Add `filter_options_page_size` and
   `api_rate_limit`.

**End with three short lists:** *Contradictions in the code* (tooltip or docblock against the SQL,
or "none found"), *Sources* (the files you read), and *Not verified* (what you could not confirm, or
"nothing").

## 4. Common mistakes

| Mistake | Instead |
|---|---|
| Explaining a column from its key or label | Explain it from `select_expression` and the join SQL |
| Calling the default filter a cut-off without a number | Show `counts.body_less` against `counts.no_filters` |
| "The export is limited to 500 rows" | Above the sync limit it is queued; only `max_export_rows` refuses |
| Saying the 180-day window narrows a report with no window totals | Say it has no effect |
| Trusting a controller docblock about defaults | Trust the dump's `counts.*.date_range` and the parser |
| Leaving out the export split of an enriched column | Use `export.csv_headers` |
| Folding several columns into one paragraph | One subsection per column, shared joins explained once |
| Writing the export section from memory | Every number comes from the dump of this report |
| Quoting a row count from a docblock | Quote `counts`; a docblock's figure is history |
| Reading forty files before the dump | Run the dump first; open only the definition, its shared classes and its enrichers |

## 5. Changing this skill

Its source is the `layup-report-skills` repository (`skills/explain-report/`), and a teammate gets a
change with `npx skills update explain-report`. An edit made only to an installed copy under
`.claude/skills/` or `.agents/skills/` is overwritten by the next update, so make it in that
repository and push it.
