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
#   <output_dir>/files/                    one PDF per item, from get-file,
#                                          named <item_id>_<filename>
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

# POST one module API action and return the httr2 response. Any failure,
# whether an HTTP error status or a network error such as a timeout, stops with
# a message that the per-item code records instead of aborting the export.
api_call <- function(action, ...) {
  resp <- httr2::request(credential$redcap_uri) |>
    httr2::req_body_form(
      token = credential$token,
      content = "externalModule",
      prefix = prefix,
      action = action,
      ...
    ) |>
    httr2::req_error(is_error = \(resp) FALSE) |>
    httr2::req_timeout(300) |>
    httr2::req_perform()
  if (httr2::resp_is_error(resp)) {
    stop(sprintf("HTTP %d: %s", httr2::resp_status(resp), httr2::resp_body_string(resp)), call. = FALSE)
  }
  resp
}

api_json <- function(action, ...) {
  api_call(action, ..., returnFormat = "json") |>
    httr2::resp_body_string() |>
    jsonlite::fromJSON(simplifyVector = FALSE)
}

# An error message on one line, for the log and the CSV
error_text <- function(e) gsub("\\s+", " ", conditionMessage(e))

# Evaluate expr, returning "ok" or the error message so one item's failure
# never stops the export.
status_of <- function(expr) {
  tryCatch({
    force(expr)
    "ok"
  }, error = error_text)
}

# get-item as a one-row tibble. JSON nulls become NA so rows bind together.
get_item_row <- function(item_id) {
  api_json("get-item", item_id = item_id) |>
    purrr::map(\(x) if (is.null(x)) NA else x) |>
    tibble::as_tibble()
}

# Fetch one item's attributes and download its PDF to files_dir. Returns a
# one-row tibble with the attributes plus the outcome of each step. If get-item
# fails, the row keeps the item_id, doc_name, and record from list-items.
export_item <- function(item_id, filename, record, local_name, files_dir) {
  details <- tryCatch(
    list(row = get_item_row(item_id), status = "ok"),
    error = \(e) list(
      row = tibble::tibble(item_id = item_id, doc_name = filename, record = record),
      status = error_text(e)
    )
  )
  if (details$status != "ok") {
    message("Item ", item_id, ": get-item failed, ", details$status)
  }

  local_file <- file.path("files", local_name)
  download_status <- status_of(
    # returnFormat only affects the format of error messages here
    api_call("get-file", item_id = item_id, returnFormat = "json") |>
      httr2::resp_body_raw() |>
      writeBin(file.path(files_dir, local_name))
  )
  if (download_status == "ok") {
    message("Item ", item_id, ": saved ", local_file)
  } else {
    message("Item ", item_id, ": download failed, ", download_status)
  }

  dplyr::mutate(
    details$row,
    local_file = if (download_status == "ok") local_file else NA_character_,
    details_status = details$status,
    download_status = download_status
  )
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
  # Prefix every filename with its unique item_id so no download overwrites
  # another, even on case-insensitive filesystems
  dplyr::mutate(local_name = paste0(item_id, "_", basename(filename))) |>
  dplyr::select(item_id, filename, record, local_name) |>
  purrr::pmap(\(item_id, filename, record, local_name) {
    export_item(item_id, filename, record, local_name, files_dir)
  }) |>
  dplyr::bind_rows()

readr::write_csv(details, details_path, na = "")

downloaded <- sum(details$download_status == "ok")
message("Wrote ", details_path, " (", nrow(details), " items) and ", downloaded, " files to ", files_dir)
failed <- sum(details$details_status != "ok" | details$download_status != "ok")
if (failed > 0) message(failed, " items had errors; see details_status and download_status in the CSV")
