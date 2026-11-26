# layup-report-skills

Claude Code skills for the **layup-reports** service, installed with the
[`skills`](https://github.com/vercel-labs/skills) CLI.

| Skill | Command | What it does |
|---|---|---|
| `explain-report` | `/explain-report <report-key>` | Explains one existing report, read-only: its pinned column, default columns, every column with the filters a user can use, each column's logic, the download (export) logic, and the data cut-offs and limits. |

## Install

Run this from the root of your **layup-reports** checkout. The skills only work inside that
repository:

```bash
npx skills@latest add dishahimesha/layup-report-skills -a claude-code
```

- To install one skill only, add `--skill explain-report`.
- To see what the repository offers without installing anything, add `--list`.
- If your machine cannot create symlinks, add `--copy`.

Restart Claude Code, or open a new session, and type `/explain-report course-report`.

### Requirements

- Node.js 22.20 or later, for `npx skills`.
- Access to this repository. It is private, so your git login must be able to clone it.
- The layup-reports development setup: PHP 8.4 and the MySQL 8 instance with the `playground`
  tenant, which must be listed in your local `TENANT_DATABASES`.
  The skill uses the team's WAMP paths (`C:/wamp64/bin/php/php8.4.15/php.exe` and
  `C:/wamp64/bin/mysql/mysql8.4.7/bin/mysql.exe`); on a different setup Claude uses your own
  binaries.

## Update and remove

```bash
npx skills update explain-report
npx skills remove explain-report
```

`npx skills check` shows whether an update is available without changing anything.

## Layout

```
skills/
  explain-report/
    SKILL.md                    the instructions Claude Code follows
    scripts/dump-definition.php read-only: loads one report definition from layup-reports
                                and writes its columns, joins, export settings, limits
                                and row counts as JSON
```

`dump-definition.php` can also be run on its own, from inside a layup-reports checkout:

```bash
"C:/wamp64/bin/php/php8.4.15/php.exe" .claude/skills/explain-report/scripts/dump-definition.php \
    course-report --out=course-report.json [--tenant=playground] [--context=<id>] [--static] [--root=<path>]
```

It writes no database row. It runs only the registry's schema probes, the profile-field read,
one read of the role templates that grant the report's permission, and up to three `COUNT`s.

## Changing a skill

Edit it here, test it from a layup-reports checkout, and push. Teammates pick the change up with
`npx skills update`. An edit made only to an installed copy is overwritten by the next update.

To add a skill, create `skills/<name>/SKILL.md` with a `name` and a `description` in its
frontmatter, and add a row to the table at the top of this file.
