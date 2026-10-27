"""
Bridge between EDM (app/Services/Import/EmailCleanser.php - the contact
import and the Email cleaner page) and the cleansing rules in
clean_email_list.py / clean_emails.py, which are used unchanged.

Reads one JSON object from stdin and writes one JSON object to stdout. A row
is {"email", "name", "mobile", "ic"}; only email is required.

  {"mode": "check", "cache": "<mx cache file>", "rows": [...]}
      -> {"results": [{"ok", "email", "message", "dns", "flag"}, ...]}
         One result per row, in order. ok false: message is the first failing
         rule, dns true when that rule is the DNS check (main() applies it
         after the shared / duplicate checks). ok true: email is the cleaned
         address (domain typo / alias corrected), message the correction
         note, if any, and flag "Role address" for a role mailbox. Runs
         validate(), record_reason() with the client name and mobile, then
         the DNS check (nonexistent / null MX / no MX / parked domain). A DNS
         lookup that times out keeps the address valid with a note, as the
         script does.

  {"mode": "shared", "rows": [...]}
  {"mode": "shared", "rows_file", "columns": {"email": i, ...}, "skip_first"}
      -> {"shared": {"<cleaned email>": <distinct customers>, ...}}
         rows_file: a staged JSON-lines file read directly (large files),
         columns the cell index per row key (null = not in the file).
         Addresses used by SHARED_EMAIL_THRESHOLD or more distinct client
         names or IC numbers across the whole file (counter / staff email).
         Rows that fail validate() / record_reason() are not counted, as in
         main().

  {"mode": "export", "results_file", "headers", "valid_file", "invalid_file"}
      -> {"valid": <rows>, "invalid": <rows>}
         Writes the two workbooks in main()'s layout: Valid = the file's
         columns + Cleaned Email, Notes, Flags; Invalid = the file's
         columns + Reason. results_file holds one JSON line per row:
         {"cells": [...], "ok", "email", "message", "flag"}.

  {"mode": "read", "path", "out", "max_rows"}
      -> {"rows": <rows written>} or {"error": "..."}
         Streams the first sheet of an .xlsx (openpyxl read-only, cached
         formula values) to out, one JSON list of strings per row, empty rows
         skipped, at most max_rows rows. Whole numbers lose the ".0", dates
         become YYYY-MM-DD (with the time when it is not midnight), TRUE /
         FALSE become "1" / "". PhpSpreadsheet would need ~1 KB of PHP memory
         per cell for the same file.

Anything the scripts print goes to stderr, so stdout stays pure JSON.
"""

import contextlib
import datetime
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import clean_email_list as cel  # noqa: E402
from clean_emails import normalize_email  # noqa: E402
from openpyxl.cell import WriteOnlyCell  # noqa: E402


def first_pass(row):
    """(ok, cleaned, message) from the email and client name rules."""
    ok, cleaned, message = cel.validate(normalize_email(row.get("email")))
    if ok:
        local, domain = cleaned.split("@")
        reason = cel.record_reason(row.get("name"), row.get("mobile"), local, domain)
        if reason:
            ok, message = False, reason
    return ok, cleaned, message


def check(rows, cache_file):
    if cache_file:
        cel.MX_CACHE_FILE = cache_file
    passed = [first_pass(r) for r in rows]
    domains = {cleaned.split("@")[1] for ok, cleaned, _ in passed if ok}
    dns = cel.load_domain_results(domains) if domains else {}

    results = []
    for ok, cleaned, message in passed:
        failed_dns = False
        flag = ""
        if ok:
            local, domain = cleaned.split("@")
            status = dns.get(domain, {}).get("status", "unknown")
            if status in cel.DOMAIN_STATUS_REASONS:
                ok, message, failed_dns = False, cel.DOMAIN_STATUS_REASONS[status], True
            else:
                if status == "unknown":
                    message = "; ".join(filter(None, [message, "DNS lookup timed out"]))
                flag = "Role address" if local in cel.ROLE_LOCAL_PARTS else ""
        results.append({
            "ok": ok, "email": cleaned, "message": message or "",
            "dns": failed_dns, "flag": flag,
        })
    return {"results": results}


def shared(rows):
    names = {}
    ics = {}
    for row in rows:
        ok, cleaned, _ = first_pass(row)
        if not ok:
            continue
        name = str(row.get("name") or "").strip().lower()
        if name:
            names.setdefault(cleaned, set()).add(name)
        ic = str(row.get("ic") or "").strip().lstrip("'").lower()
        if ic not in cel.TRIVIAL_IC_VALUES:
            ics.setdefault(cleaned, set()).add(ic)
    out = {}
    for email in set(names) | set(ics):
        sharers = max(len(names.get(email, ())), len(ics.get(email, ())))
        if sharers >= cel.SHARED_EMAIL_THRESHOLD:
            out[email] = sharers
    return {"shared": out}


def file_rows(path, columns, skip_first):
    """Rows of a staged JSON-lines file as {"email", "name", "mobile", "ic"}."""
    with open(path, encoding="utf-8") as fh:
        for n, line in enumerate(fh):
            if skip_first and n == 0:
                continue
            cells = json.loads(line)
            row = {}
            for key, col in columns.items():
                row[key] = cells[col] if col is not None and col < len(cells) else ""
            yield row


def text_cells(ws, values):
    """Cells as text: a value such as "=HYPERLINK(...)" from the uploaded
    file stays a string and never becomes a live formula."""
    cells = []
    for v in values:
        cell = WriteOnlyCell(ws, value=v)
        if isinstance(v, str) and v.startswith("="):
            cell.data_type = "s"
        cells.append(cell)
    return cells


def export(request):
    headers = list(request.get("headers") or [])
    valid_wb = cel.openpyxl.Workbook(write_only=True)
    valid_ws = valid_wb.create_sheet("Valid")
    valid_ws.append(headers + ["Cleaned Email", "Notes", "Flags"])
    invalid_wb = cel.openpyxl.Workbook(write_only=True)
    invalid_ws = invalid_wb.create_sheet("Invalid")
    invalid_ws.append(headers + ["Reason"])
    counts = {"valid": 0, "invalid": 0}
    with open(request["results_file"], encoding="utf-8") as fh:
        for line in fh:
            r = json.loads(line)
            cells = list(r.get("cells") or [])
            cells += [""] * (len(headers) - len(cells))
            if r.get("ok"):
                valid_ws.append(text_cells(valid_ws, cells + [r.get("email"), r.get("message"), r.get("flag")]))
                counts["valid"] += 1
            else:
                invalid_ws.append(text_cells(invalid_ws, cells + [r.get("message")]))
                counts["invalid"] += 1
    valid_wb.save(request["valid_file"])
    invalid_wb.save(request["invalid_file"])
    return counts


def cell_text(v):
    if v is None:
        return ""
    if isinstance(v, bool):
        return "1" if v else ""
    if isinstance(v, float) and v.is_integer():
        return str(int(v))
    if isinstance(v, datetime.datetime):
        return v.strftime("%Y-%m-%d" if v.time() == datetime.time() else "%Y-%m-%d %H:%M:%S")
    if isinstance(v, datetime.date):
        return v.strftime("%Y-%m-%d")
    return str(v)


def read(request):
    max_rows = int(request.get("max_rows") or 0)
    written = 0
    try:
        # A file object, so openpyxl does not reject PHP's upload name (.tmp).
        with open(request["path"], "rb") as src:
            wb = cel.openpyxl.load_workbook(src, read_only=True, data_only=True)
            ws = wb.worksheets[0]
            ws.reset_dimensions()  # some exporters write a wrong sheet size
            with open(request["out"], "w", encoding="utf-8") as out:
                for row in ws.iter_rows(values_only=True):
                    cells = [cell_text(v).strip() for v in row]
                    while cells and cells[-1] == "":
                        cells.pop()
                    if not cells:
                        continue
                    out.write(json.dumps(cells, ensure_ascii=False) + "\n")
                    written += 1
                    if max_rows and written >= max_rows:
                        break
            wb.close()
    except Exception as exc:  # corrupt / not a workbook
        return {"error": str(exc) or exc.__class__.__name__}
    return {"rows": written}


def main():
    request = json.loads(sys.stdin.buffer.read().decode("utf-8"))
    rows = request.get("rows") or []
    with contextlib.redirect_stdout(sys.stderr):
        if request.get("mode") == "shared":
            if request.get("rows_file"):
                rows = file_rows(request["rows_file"], request.get("columns") or {}, request.get("skip_first"))
            response = shared(rows)
        elif request.get("mode") == "export":
            response = export(request)
        elif request.get("mode") == "read":
            response = read(request)
        else:
            response = check(rows, request.get("cache"))
    sys.stdout.write(json.dumps(response))


if __name__ == "__main__":
    main()
