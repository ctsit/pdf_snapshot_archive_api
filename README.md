# PDF Snapshot Archive API

This REDCap External Module extends the REDCap API so that API users can list and download items in the **PDF Snapshot Archive** folder of a project's File Repository.

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
| `list-items`, `get-item` | File Repository right |
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
| `action` | `list-items`, `get-item`, or `get-file` |
| `returnFormat` | `json`, `csv`, or `xml`. If it's left out, REDCap defaults to `xml`. |

### list-items

This action lists every archive item the user can see. Each item has three fields:
- `item_id`
- `filename`
- `record`

To list only one record's items, add the `record` parameter.

```sh
curl -F token=$TOKEN -F content=externalModule -F prefix=pdf_snapshot_archive_api \
     -F action=list-items -F returnFormat=json https://redcap.example.org/api/
```

### get-item

This action returns every attribute of the item identified by `item_id`. The file itself isn't included. The attributes are:
- The `redcap_surveys_pdf_archive` columns: `doc_id`, `record`, `event_id`, `survey_id`, `instance`, `identifier`, `version`, `type`, `consent_id`, `consent_form_id`, `snapshot_id`, `contains_completed_consent` and `project_id`. `ip` is included only when the system is configured to show it.
- The file metadata: `doc_name`, `doc_size` and `stored_date`.
- Fields derived from the project: `form_name`, `survey_title`, `event_name`, `arm_num` and `is_econsent`.

```sh
curl -F token=$TOKEN -F content=externalModule -F prefix=pdf_snapshot_archive_api \
     -F action=get-item -F item_id=1234 -F returnFormat=json https://redcap.example.org/api/
```

### get-file

This action downloads the PDF for the item identified by `item_id`.

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
| 400 | Missing or invalid `item_id`, or a token that isn't tied to a project |
| 403 | The token's user lacks the required rights |
| 404 | The item doesn't exist, has been deleted, or is outside the user's DAG |
| 406 | Unsupported `returnFormat` |
| 500 | The file couldn't be read from storage |
| 501 | Unknown action |

## Manual testing

`tests/manual/test_api.php` and `tests/manual/test_api.R` run the actions against a real project and print PASS or FAIL for each check. Both scripts need two files, which git ignores:

- `tests/manual/.env` must set `TEST_PROJECT_PID`, the project ID to test against.
- `tests/manual/credentials.csv` must be in the format `REDCapR::retrieve_credential_local()` reads, with the columns `redcap_uri,username,project_id,token,comment`. `redcap_uri` is the API URL.

The token's user needs the rights listed above, and the project should already have at least one item in its PDF Snapshot Archive.

```sh
php tests/manual/test_api.php
Rscript tests/manual/test_api.R
```

Downloaded files are saved to `tests/manual/output/`.
