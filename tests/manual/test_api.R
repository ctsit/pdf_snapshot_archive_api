#!/usr/bin/env Rscript
# Manual test of the PDF Snapshot Archive API against a live REDCap project.
#
# Usage: Rscript tests/manual/test_api.R
#
# Reads TEST_PROJECT_PID from tests/manual/.env, then reads the matching row
# of tests/manual/credentials.csv with REDCapR::retrieve_credential_local().
# Requires the REDCapR, httr2, dotenv, and jsonlite packages.

prefix <- "pdf_snapshot_archive_api"

script_dir <- local({
  file_arg <- grep("^--file=", commandArgs(trailingOnly = FALSE), value = TRUE)
  if (length(file_arg) == 1) dirname(normalizePath(sub("^--file=", "", file_arg))) else getwd()
})

env_path <- file.path(script_dir, ".env")
if (file.exists(env_path)) {
  dotenv::load_dot_env(env_path)
} else {
  message("No .env file found at ", env_path)
}
pid <- Sys.getenv("TEST_PROJECT_PID")
if (pid == "") {
  message("TEST_PROJECT_PID is not set in ", env_path)
  quit(status = 2)
}

credential <- REDCapR::retrieve_credential_local(
  path_credential = file.path(script_dir, "credentials.csv"),
  project_id = as.integer(pid),
  # Allow http:// so the tests can run against local development instances
  check_url = FALSE
)
if (!startsWith(credential$redcap_uri, "https://")) {
  message("Warning: ", credential$redcap_uri, " is not an https address; the token is sent unencrypted.")
}
cat("Testing project", pid, "at", credential$redcap_uri, "as", credential$username, "\n\n")

api_call <- function(...) {
  params <- c(
    list(token = credential$token, content = "externalModule", prefix = prefix),
    list(...)
  )
  resp <- httr2::request(credential$redcap_uri) |>
    httr2::req_body_form(!!!params) |>
    httr2::req_error(is_error = function(resp) FALSE) |>
    httr2::req_timeout(120) |>
    httr2::req_perform()
  list(status = httr2::resp_status(resp), raw = httr2::resp_body_raw(resp))
}

body_text <- function(r) rawToChar(r$raw)
body_json <- function(r) jsonlite::fromJSON(body_text(r), simplifyVector = FALSE)
save_output <- function(name, raw) {
  out_dir <- file.path(script_dir, "output")
  dir.create(out_dir, showWarnings = FALSE, recursive = TRUE)
  writeBin(raw, file.path(out_dir, name))
  file.path("output", name)
}
snippet <- function(r) substr(gsub("\\s+", " ", body_text(r)), 1, 200)

failures <- 0L
check <- function(label, ok, detail = "") {
  ok <- isTRUE(ok)
  if (!ok) failures <<- failures + 1L
  cat(if (ok) "PASS" else "FAIL", " ", label, if (nzchar(detail)) paste0("  (", detail, ")"), "\n", sep = "")
}

# Built-in framework actions
r <- api_call(action = "__version", returnFormat = "json")
check("__version", r$status == 200, snippet(r))

r <- api_call(action = "__actions", returnFormat = "json")
check(
  "__actions lists list-items, get-item, get-file",
  r$status == 200 && all(sapply(c("list-items", "get-item", "get-file"), grepl, body_text(r), fixed = TRUE)),
  snippet(r)
)

# list-items
r <- api_call(action = "list-items", returnFormat = "json")
items <- if (r$status == 200) body_json(r) else NULL
check("list-items json", r$status == 200 && is.list(items), paste(length(items), "items"))
if (length(items) > 0) {
  check("list-items rows have item_id, filename, record",
        identical(names(items[[1]]), c("item_id", "filename", "record")))
}

r <- api_call(action = "list-items", returnFormat = "csv")
ok <- r$status == 200 && grepl("item_id", body_text(r), fixed = TRUE)
check("list-items csv", ok, if (ok) paste("saved to", save_output("list-items.csv", r$raw)) else snippet(r))

r <- api_call(action = "list-items", returnFormat = "xml")
check("list-items xml", r$status == 200 && startsWith(body_text(r), "<?xml"), snippet(r))

if (length(items) == 0) {
  cat("\nThe PDF Snapshot Archive is empty; skipping item and file tests.\n")
} else {
  first <- items[[1]]

  r <- api_call(action = "list-items", record = first$record, returnFormat = "json")
  filtered <- if (r$status == 200) body_json(r) else list()
  check(
    "list-items record filter",
    r$status == 200 && length(filtered) > 0 && all(sapply(filtered, `[[`, "record") == first$record),
    paste(length(filtered), "items for record", first$record)
  )

  # get-item
  r <- api_call(action = "get-item", item_id = first$item_id, returnFormat = "json")
  item <- if (r$status == 200) body_json(r) else list()
  check(
    "get-item json",
    r$status == 200 && identical(as.character(item$item_id), as.character(first$item_id)) &&
      identical(item$doc_name, first$filename) && identical(item$record, first$record),
    snippet(r)
  )

  r <- api_call(action = "get-item", item_id = first$item_id, returnFormat = "csv")
  ok <- r$status == 200 && grepl("doc_name", body_text(r), fixed = TRUE)
  check("get-item csv", ok,
        if (ok) paste("saved to", save_output(paste0("get-item_", first$item_id, ".csv"), r$raw)) else snippet(r))

  # get-file
  r <- api_call(action = "get-file", item_id = first$item_id)
  is_pdf <- length(r$raw) >= 4 && identical(rawToChar(r$raw[1:4]), "%PDF")
  ok <- r$status == 200 && is_pdf
  check("get-file returns a PDF", ok,
        if (ok) paste(length(r$raw), "bytes saved to", save_output(basename(first$filename), r$raw)) else snippet(r))
}

# Error handling
r <- api_call(action = "get-item", returnFormat = "json")
check("get-item without item_id returns 400", r$status == 400, paste("status", r$status))

r <- api_call(action = "get-item", item_id = "abc", returnFormat = "json")
check("get-item with non-integer item_id returns 400", r$status == 400, paste("status", r$status))

r <- api_call(action = "get-item", item_id = "2147483000", returnFormat = "json")
check("get-item with unknown item_id returns 404", r$status == 404, paste("status", r$status))

r <- api_call(action = "get-file", item_id = "2147483000")
check("get-file with unknown item_id returns 404", r$status == 404, paste("status", r$status))

r <- api_call(action = "no-such-action", returnFormat = "json")
check("unknown action is rejected", r$status == 400, paste("status", r$status))

cat("\n", if (failures == 0) "All checks passed." else paste(failures, "check(s) failed."), "\n", sep = "")
quit(status = if (failures == 0) 0 else 1)
