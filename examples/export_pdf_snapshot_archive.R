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
#   <output_dir>/pdf_snapshot_archive.csv  one row per item, from get-items
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

# Download one item's PDF to files_dir. Returns "ok" or the error message, so
# one item's failure never stops the export.
download_item <- function(item_id, local_name, files_dir) {
  status <- tryCatch({
    # returnFormat only affects the format of error messages here
    api_call("get-file", item_id = item_id, returnFormat = "json") |>
      httr2::resp_body_raw() |>
      writeBin(file.path(files_dir, local_name))
    "ok"
  }, error = error_text)
  if (status == "ok") {
    message("Item ", item_id, ": saved ", file.path("files", local_name))
  } else {
    message("Item ", item_id, ": download failed, ", status)
  }
  status
}

# Every attribute of every item in one call. JSON nulls become NA so rows bind together.
items <- api_json("get-items") |>
  purrr::map(\(item) purrr::map(item, \(x) if (is.null(x)) NA else x)) |>
  dplyr::bind_rows()
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
  dplyr::mutate(
    local_name = paste0(item_id, "_", basename(doc_name)),
    download_status = purrr::map2_chr(item_id, local_name, \(id, name) download_item(id, name, files_dir)),
    local_file = dplyr::if_else(download_status == "ok", file.path("files", local_name), NA_character_)
  ) |>
  dplyr::select(-local_name) |>
  dplyr::relocate(download_status, .after = local_file)

readr::write_csv(details, details_path, na = "")

downloaded <- sum(details$download_status == "ok")
message("Wrote ", details_path, " (", nrow(details), " items) and ", downloaded, " files to ", files_dir)
if (downloaded < nrow(details)) {
  message(nrow(details) - downloaded, " downloads failed; see download_status in the CSV")
}
