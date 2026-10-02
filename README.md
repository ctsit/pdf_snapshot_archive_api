# PDF Snapshot Archive API

This REDCap External Module extends the REDCap API so that API users can read the details of, and download, the items in the **PDF Snapshot Archive** folder of a project's File Repository.

REDCap's File Repository API methods can't reach this folder. It is a virtual folder built from `redcap_surveys_pdf_archive`, not a regular File Repository folder. It holds PDF snapshots created by PDF Snapshot triggers and by the e-Consent Framework.

## Prerequisites

- REDCap >= 17.5.0

## Installation

- Clone this repo into `<redcap-root>/modules/pdf_snapshot_archive_api_v0.0.0`.
- Enable the module in the Control Center, then enable it on each project that needs it.

## Access rules

The module applies the same rules as the File Repository page. The rules are checked against the user who owns the API token.

| Action | Requirement |
|---|---|
| `get-items` | File Repository right |
| `get-file` | File Repository right, and Full Data Set export rights. If the snapshot came from a survey, the user also needs Full Data Set export rights on that instrument. |

- Users in a Data Access Group see only items for records in their DAG.
- Super users skip the rights checks.
- A project-level API token is required. Super API tokens and unauthenticated requests are rejected.
- Each `get-file` download is written to the project log as "Download PDF Snapshot File (API)".

## Using the API

Every request is a POST to the REDCap API endpoint with these parameters:

| Parameter | Value |
|---|---|
| `token` | a project API token |
| `content` | `externalModule` |
| `prefix` | `pdf_snapshot_archive_api` |
| `action` | `get-items` or `get-file` |
| `returnFormat` | `json`, `csv`, or `xml`. If it's left out, REDCap defaults to `xml`. |

### get-items

This action returns every attribute of every archive item the user can see. The files themselves aren't included. The attributes are:
- `item_id`, the ID to pass to `get-file`.
- The `redcap_surveys_pdf_archive` columns: `doc_id`, `record`, `event_id`, `survey_id`, `instance`, `identifier`, `version`, `type`, `consent_id`, `consent_form_id`, `snapshot_id`, `contains_completed_consent` and `project_id`. `ip` is included only when the system is configured to show it.
- The file metadata: `doc_name`, `doc_size` and `stored_date`.
- Fields derived from the project: `form_name`, `survey_title`, `event_name`, `arm_num` and `is_econsent`.

Two optional parameters narrow the result. Each takes an array (`item_ids[]=24&item_ids[]=26`) or a comma-separated list (`item_ids=24,26`). If you give both, an item must match both.

| Parameter | Returns only |
|---|---|
| `item_ids` | the items with these IDs |
| `records` | the items for these REDCap record names |

As with REDCap's Export Records, any IDs or records that don't match an item the user can see are left out of the result rather than causing an error. A request that matches nothing returns an empty list.

```sh
# Every item
curl -F token=$TOKEN -F content=externalModule -F prefix=pdf_snapshot_archive_api \
     -F action=get-items -F returnFormat=json https://redcap.example.org/api/

# Items 24 and 26
curl -F token=$TOKEN -F content=externalModule -F prefix=pdf_snapshot_archive_api \
     -F action=get-items -F "item_ids[]=24" -F "item_ids[]=26" -F returnFormat=json https://redcap.example.org/api/

# Every item for records 1 and 2, as CSV
curl -F token=$TOKEN -F content=externalModule -F prefix=pdf_snapshot_archive_api \
     -F action=get-items -F records=1,2 -F returnFormat=csv https://redcap.example.org/api/
```

### get-file

This action downloads the PDF for the item identified by `item_id`. It takes one item per call.

```sh
curl -F token=$TOKEN -F content=externalModule -F prefix=pdf_snapshot_archive_api \
     -F action=get-file -F item_id=1234 -o snapshot.pdf https://redcap.example.org/api/
```

### Built-in actions

The External Module Framework also provides these actions:
- `__version` returns the module version.
- `__actions` lists the actions with their descriptions.
- `__info` returns the module metadata, including the authors.

### Errors

| Status | Meaning |
|---|---|
| 400 | A missing or invalid `item_id` for `get-file`, a non-integer value in `item_ids`, an action this module doesn't define, or a token that isn't tied to a project |
| 403 | The token's user lacks the required rights |
| 404 | `get-file` only: the item doesn't exist, has been deleted, or is outside the user's DAG |
| 500 | The file couldn't be read from storage |

## Example: export the whole archive

[`examples/export_pdf_snapshot_archive.R`](examples/export_pdf_snapshot_archive.R) exports a project's entire PDF Snapshot Archive. It calls `get-items` once, then calls `get-file` for every item.

```sh
Rscript examples/export_pdf_snapshot_archive.R credentials.csv 123 my_export
```

The arguments are a credentials file, a project ID and an optional output directory. The credentials file must be in the format `REDCapR::retrieve_credential_local()` reads. The output directory defaults to `pdf_snapshot_archive_pid<project_id>_<date>`. The script writes:

- `pdf_snapshot_archive.csv`, with one row per item. Each row has every `get-items` attribute, plus:
  - `local_file`: the path of the downloaded PDF.
  - `download_status`: `ok`, or the `get-file` error.
- `files/`, with every PDF in the archive. Each file is named `<item_id>_<filename>`, so no download can overwrite another.

The script needs R 4.1 or later and the R packages REDCapR, httr2, jsonlite, dplyr, purrr, readr and tibble. The token's user needs the rights listed under [Access rules](#access-rules).

If `get-file` fails for an item, the script records the error in `download_status` and moves on to the next item. This covers HTTP errors such as a missing right, and network errors such as a timeout. If `get-items` fails, there is nothing to export, so the script stops.

## Manual testing

`tests/manual/test_api.php` runs the actions against a real project and prints PASS or FAIL for each check. It reads two files from a config directory:

- `.env` must set `TEST_PROJECT_PID`, the project ID to test against.
- `credentials.csv` must be in the format `REDCapR::retrieve_credential_local()` reads, with the columns `redcap_uri,username,project_id,token,comment`. `redcap_uri` is the API URL.

The config directory is the one named by the `REDCAP_EM_TEST_DIR` environment variable. If that isn't set, it's `tests/manual/`, where git ignores both files. Downloaded files and CSV responses are saved to `output/` in the same directory.

**If your web server serves the module directory, keep these files out of it.** This is usually the case when you test from a working copy inside REDCap's `modules/` folder. The test script refuses web requests, but the server will still hand `.env`, `credentials.csv` and the downloaded PDFs to anyone who asks for them. Put them in a directory outside the web root and point `REDCAP_EM_TEST_DIR` at it.

The token's user needs the rights listed above, and the project should already have at least one item in its PDF Snapshot Archive.

To build a test project, create a new project from [`examples/eConsent_test_project.xml`](examples/eConsent_test_project.xml). It has an e-Consent survey, "Informed Consent", whose PDF snapshots are saved to the File Repository. Complete the survey for a record or two to fill the PDF Snapshot Archive.

```sh
REDCAP_EM_TEST_DIR=~/redcap_em_test/pdf_snapshot_archive_api php tests/manual/test_api.php
```
