#!/usr/bin/env Rscript
# Export the full PDF Snapshot Archive of a REDCap project: a CSV with every
# attribute of every item, plus every PDF in the archive.
#
# Usage:
#   Rscript export_pdf_snapshot_archive.R <credentials.csv> <project_id> [output_dir]
#
# <credentials.csv> is read with REDCapR::retrieve_credential_local(), so it has
# the columns redcap_uri, username, project_id, token, and comment. redcap_uri is
# the API URL. output_dir defaults to pdf_snapshot_archive_pid<project_id>_<date>.
#
# Writes:
#   <output_dir>/pdf_snapshot_archive.csv  one row per item, from get-item
#   <output_dir>/files/                    one PDF per item, from get-file
#
# Requires the REDCap PDF Snapshot Archive API module enabled on the project, and
# the R packages REDCapR, httr2, jsonlite, dplyr, purrr, readr, and tibble.

prefix <- "pdf_snapshot_archive_api"

args <- commandArgs(trailingOnly = TRUE)
if (length(args) < 2) {
  stop("Usage: Rscript export_pdf_snapshot_archive.R <credentials.csv> <project_id> [output_dir]")
}
path_credential <- args[1]
project_id <- as.integer(args[2])
output_dir <- if (length(args) >= 3) args[3] else
  sprintf("pdf_snapshot_archive_pid%d_%s", project_id, format(Sys.Date(), "%Y-%m-%d"))

credential <- REDCapR::retrieve_credential_local(
  path_credential = path_credential,
  project_id = project_id,
  # Allow http:// for local development instances
  check_url = FALSE
)
if (!startsWith(credential$redcap_uri, "https://")) {
  message("Warning: ", credential$redcap_uri, " is not an https address; the token is sent unencrypted.")
}

# POST one module API action. Returns the httr2 response; stops on HTTP errors
# unless allow_error is TRUE.
api_call <- function(action, ..., allow_error = FALSE) {
  httr2::request(credential$redcap_uri) |>
    httr2::req_body_form(
      token = credential$token,
      content = "externalModule",
      prefix = prefix,
      action = action,
      ...
    ) |>
    httr2::req_error(is_error = function(resp) !allow_error && httr2::resp_is_error(resp)) |>
    httr2::req_timeout(300) |>
    httr2::req_perform()
}

api_json <- function(action, ...) {
  api_call(action, ..., returnFormat = "json") |>
    httr2::resp_body_string() |>
    jsonlite::fromJSON(simplifyVector = FALSE)
}

# Fetch one item's attributes, download its PDF to files_dir, and return a
# one-row tibble describing both. JSON nulls become NA so rows bind together.
export_item <- function(item_id, local_name, files_dir) {
  row <- api_json("get-item", item_id = item_id) |>
    purrr::map(\(x) x %||% NA) |>
    tibble::as_tibble()

  resp <- api_call("get-file", item_id = item_id, allow_error = TRUE)
  if (httr2::resp_is_error(resp)) {
    status <- sprintf("HTTP %d: %s", httr2::resp_status(resp), httr2::resp_body_string(resp))
    message("Item ", item_id, ": download failed, ", status)
    return(dplyr::mutate(row, local_file = NA_character_, download_status = status))
  }

  local_file <- file.path("files", local_name)
  writeBin(httr2::resp_body_raw(resp), file.path(files_dir, local_name))
  message("Item ", item_id, ": saved ", local_file)
  dplyr::mutate(row, local_file = local_file, download_status = "ok")
}

items <- api_json("list-items") |> dplyr::bind_rows()
message(nrow(items), " items in the PDF Snapshot Archive of project ", project_id)

files_dir <- file.path(output_dir, "files")
dir.create(files_dir, showWarnings = FALSE, recursive = TRUE)
details_path <- file.path(output_dir, "pdf_snapshot_archive.csv")

if (nrow(items) == 0) {
  readr::write_csv(tibble::tibble(item_id = integer()), details_path)
  message("Wrote an empty ", details_path)
  quit(status = 0)
}

details <- items |>
  dplyr::mutate(
    local_name = basename(filename),
    # Prefix duplicated filenames with the item_id so no download overwrites another
    local_name = dplyr::if_else(
      duplicated(local_name) | duplicated(local_name, fromLast = TRUE),
      paste0(item_id, "_", local_name),
      local_name
    )
  ) |>
  dplyr::select(item_id, local_name) |>
  purrr::pmap(\(item_id, local_name) export_item(item_id, local_name, files_dir)) |>
  dplyr::bind_rows()

readr::write_csv(details, details_path, na = "")

downloaded <- sum(details$download_status == "ok")
message("Wrote ", details_path, " (", nrow(details), " items) and ", downloaded, " files to ", files_dir)
