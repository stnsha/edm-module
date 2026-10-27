"""
Phase 1 - Raw Data Cleansing for 'Alpro Email Address Full List.xlsx'

Reads the three source sheets (Xilnex, Alpro One Click, Alpro Apps),
applies the Phase 1 validation rules, deduplicates, and writes:
  - Valid Email Addresses.xlsx           (clean, ready to use)
  - Excluded Email Addresses.xlsx        (hard-fail, with reason)
  - Possible Invalid Email Addresses.xlsx (soft-fail review list, with reason)

Hard-fail exclusions (Excluded file) are syntactically/structurally
invalid: bad syntax, placeholder values, non-recognized domains, etc.

Soft-fail flags (Possible Invalid file) are syntactically valid emails on
a recognized provider domain that still show patterns suggesting they are
not a genuine personal mailbox (reused across many distinct customers,
keyboard-mash usernames, single-letter usernames, generic non-personal
words). These are REMOVED from the Valid file per business decision.

Scope note: rules requiring live SMTP mailbox verification, ESP bounce
history, or a suppression list (validation rules 9, 13, 14, 16, 17) are
not applied here because no such data source exists yet for this raw
export. Those are covered by the Phase 2 closed-loop verified-column
workflow once ESP bounce reports become available.
"""

import re
import sys
import unicodedata
from collections import OrderedDict, defaultdict

import openpyxl

SOURCE_FILE = "Alpro Email Address Full List.xlsx"
VALID_FILE = "Valid Email Addresses.xlsx"
EXCLUDED_FILE = "Excluded Email Addresses.xlsx"
POSSIBLE_INVALID_FILE = "Possible Invalid Email Addresses.xlsx"

SHEETS = ["Xilnex", "Alpro One Click", "Alpro Apps"]

# Recognized primary providers whitelist (strict).
CANONICAL_DOMAINS = [
    "gmail.com",
    "yahoo.com",
    "yahoo.com.my",
    "hotmail.com",
    "outlook.com",
    "live.com",
    "qq.com",
    "vip.qq.com",
    "foxmail.com",
    "icloud.com",
    "163.com",
    "126.com",
]
CANONICAL_SET = set(CANONICAL_DOMAINS)

# Regional/alias domains for the same 4 approved providers (same brand,
# different country TLD or historical alias). These are normalized to
# their canonical domain rather than excluded.
ALIAS_TO_CANONICAL = {
    "yahoo.co.uk": "yahoo.com",
    "yahoo.com.sg": "yahoo.com",
    "yahoo.com.hk": "yahoo.com",
    "yahoo.com.tw": "yahoo.com",
    "yahoo.com.au": "yahoo.com",
    "rocketmail.com": "yahoo.com",
    "hotmail.my": "hotmail.com",
    "hotmail.co.uk": "hotmail.com",
    "outlook.my": "outlook.com",
    "live.com.my": "live.com",
    "me.com": "icloud.com",
    "gamil.com": "gmail.com",
}

PLACEHOLDER_VALUES = {
    "", "null", "nil", "none", "na", "n/a", "unknown", "test", "dummy",
    "placeholder",
}
PLACEHOLDER_USERNAMES = {
    "test", "tester", "testing", "dummy", "sample", "example", "none",
    "na", "n/a", "nil", "unknown", "placeholder", "xxx", "asdf", "temp",
    "noemail", "nomail", "notprovided", "notgiven", "abc", "123",
}

# Delimiters used to split a username into tokens for exact keyword matching
# (e.g. "sunesh.test" -> ["sunesh", "test"]).
TOKEN_SPLIT_RE = re.compile(r"[._\-+]+")

# A username entirely composed of digits wrapped around exactly one
# placeholder keyword (e.g. "nil9517", "58976nil", "test01234") is a very
# high-confidence placeholder/auto-generated account, not a real name.
DIGIT_WRAPPED_KEYWORD_RES = [
    re.compile(r"^\d*" + re.escape(kw) + r"\d*$") for kw in PLACEHOLDER_USERNAMES
]

# A keyword repeated back-to-back with nothing else (e.g. "testtest").
REPEATED_KEYWORD_RE = re.compile(
    r"^(" + "|".join(re.escape(kw) for kw in PLACEHOLDER_USERNAMES) + r")\1+$"
)

# Lower-confidence signal: a placeholder keyword appears anywhere in the
# username AND the username also contains a run of 3+ digits (e.g.
# "zerotest0755", "testingv6394"). Real personal names combined with a
# multi-digit run and a QA-style keyword are almost always auto-generated
# test/dummy accounts rather than genuine customers.
#
# Restricted to distinctive keywords only -- short/common ones like "nil"
# and "na" show up constantly inside real names ("nizamnil", "farhana",
# "diana") and would false-positive if combined with an unrelated digit
# run elsewhere in the username, so they are excluded from this fuzzy check
# (they are still caught precisely by the digit-wrapped and token rules
# above when actually adjacent to digits or delimited).
FUZZY_SUBSTRING_KEYWORDS = {
    "test", "tester", "testing", "dummy", "sample", "example",
    "placeholder", "noemail", "nomail", "notprovided", "notgiven",
    "unknown", "temp", "xxx", "asdf",
}
DIGIT_RUN_RE = re.compile(r"\d{3,}")


def is_placeholder_username(local):
    """
    Returns a reason string if `local` looks like a placeholder/test
    username beyond a bare exact match, else None. Deliberately avoids
    plain substring matching against real names (e.g. "ratnanilawi",
    "bernadettestamaria", "hottest_ak" must NOT match).
    """
    if local in PLACEHOLDER_USERNAMES:
        return "Placeholder username"

    for token in TOKEN_SPLIT_RE.split(local):
        if token in PLACEHOLDER_USERNAMES:
            return "Placeholder username (delimited token)"

    for pat in DIGIT_WRAPPED_KEYWORD_RES:
        if pat.match(local):
            return "Placeholder username (digit-adjacent pattern)"

    if REPEATED_KEYWORD_RE.match(local):
        return "Placeholder username (repeated keyword)"

    if DIGIT_RUN_RE.search(local) and any(kw in local for kw in FUZZY_SUBSTRING_KEYWORDS):
        return "Placeholder-like username (test/dummy pattern with digits)"

    return None


ROLE_LOCAL_PARTS = {
    "info", "sales", "support", "admin", "noreply", "no-reply", "contact",
    "help", "hello", "service", "marketing", "billing", "office", "enquiry",
    "enquiries", "feedback", "webmaster", "postmaster", "abuse",
}

# --- Soft-fail "possible invalid" detection -------------------------------
# These addresses pass hard validation (syntax + recognized domain) but
# show patterns suggesting they are not a genuine personal mailbox.

# An email associated with this many or more distinct customer identities
# (name or IC) is very unlikely to be one person's real mailbox -- almost
# always a staff/counter default entered for walk-ins without an email.
REUSE_THRESHOLD = 5

TRIVIAL_IC_VALUES = {"", "0", "1", "-", "na", "n/a", "nil", "none"}

GENERIC_NONPERSONAL_WORDS = {
    "customer", "customer1", "member", "members", "walkin", "walk-in",
    "guest", "guests", "user", "users", "staff", "cashier", "counter",
    "store", "outlet", "email", "mail", "account", "pelanggan",
}

KEYBOARD_MASH_PATTERNS = {
    "qwerty", "asdfgh", "asdfghjkl", "zxcvbn", "qazwsx", "qweasd",
    "asdasd", "qwerty123", "123qwe", "1qaz2wsx",
}

SINGLE_CHAR_RUN_RE = re.compile(r"^(.)\1+$")
GENERIC_DIGIT_WRAPPED_RES = [
    re.compile(r"^\d*" + re.escape(w) + r"\d*$") for w in GENERIC_NONPERSONAL_WORDS
]


def is_low_entropy(local):
    letters_only = re.sub(r"\d", "", local)
    if not letters_only:
        return False
    if SINGLE_CHAR_RUN_RE.match(letters_only):
        return True
    if letters_only in KEYBOARD_MASH_PATTERNS:
        return True
    if len(local) >= 6:
        unique_ratio = len(set(local)) / len(local)
        if unique_ratio <= 0.3:
            return True
    return False


def is_generic_nonpersonal(local):
    if local in GENERIC_NONPERSONAL_WORDS:
        return True
    for token in TOKEN_SPLIT_RE.split(local):
        if token in GENERIC_NONPERSONAL_WORDS:
            return True
    for pat in GENERIC_DIGIT_WRAPPED_RES:
        if pat.match(local):
            return True
    return False


def possible_invalid_reasons(email, distinct_names, distinct_ics):
    local = email.split("@")[0]
    reasons = []

    max_distinct = max(distinct_names, distinct_ics)
    if max_distinct >= REUSE_THRESHOLD:
        reasons.append(
            "Reused across %d distinct customer identities (possible shared/staff/counter email)"
            % max_distinct
        )

    if len(local) <= 2:
        reasons.append("Very short username (<=2 chars)")

    if is_low_entropy(local):
        reasons.append("Low-entropy / keyboard-mash username")

    if is_generic_nonpersonal(local):
        reasons.append("Generic non-personal username")

    return reasons


# RFC-lite email syntax check.
EMAIL_RE = re.compile(
    r"^[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$"
)

# Strip ascii whitespace plus common hidden/invisible unicode characters:
# NBSP (00A0), zero-width space (200B), ZWNJ (200C), ZWJ (200D),
# word joiner (2060), BOM / zero-width no-break space (FEFF).
HIDDEN_CHARS_RE = re.compile(
    "[\\s ​‌‍⁠﻿]+"
)


def strip_hidden_chars(s):
    return HIDDEN_CHARS_RE.sub("", s)


def normalize_email(raw):
    if raw is None:
        return ""
    s = str(raw)
    s = unicodedata.normalize("NFKC", s)
    s = strip_hidden_chars(s)
    return s.lower()


def edit_distance_1_candidates(domain):
    """Return canonical domains within Levenshtein distance 1 of domain."""
    candidates = []
    for canon in CANONICAL_DOMAINS:
        if abs(len(domain) - len(canon)) > 1:
            continue
        if _levenshtein_le1(domain, canon):
            candidates.append(canon)
    return candidates


def _levenshtein_le1(a, b):
    if a == b:
        return True
    la, lb = len(a), len(b)
    if abs(la - lb) > 1:
        return False
    if la == lb:
        diffs = sum(1 for x, y in zip(a, b) if x != y)
        return diffs == 1
    if la > lb:
        a, b = b, a
        la, lb = lb, la
    i = j = 0
    edits = 0
    while i < la and j < lb:
        if a[i] == b[j]:
            i += 1
            j += 1
        else:
            edits += 1
            if edits > 1:
                return False
            j += 1
    return True


def validate(email):
    """
    Returns (status, reason, corrected_email, is_role_based)
    status: 'valid' or 'excluded'
    """
    if email in PLACEHOLDER_VALUES:
        return "excluded", "Blank/placeholder value", email, False

    if len(email) > 254:
        return "excluded", "Exceeds 254 characters", email, False

    if email.count("@") != 1:
        return "excluded", "Invalid syntax (missing/multiple @)", email, False

    local, domain = email.split("@")

    if not local or not domain:
        return "excluded", "Missing username or domain", email, False

    if ".." in email or local.startswith(".") or local.endswith(".") \
            or domain.startswith(".") or domain.endswith("."):
        return "excluded", "Consecutive dots / malformed punctuation", email, False

    if not EMAIL_RE.match(email):
        return "excluded", "Invalid syntax", email, False

    placeholder_reason = is_placeholder_username(local)
    if placeholder_reason:
        return "excluded", placeholder_reason, email, False

    if local.isdigit():
        return "excluded", "Username is entirely numeric", email, False

    if domain not in CANONICAL_SET:
        if domain in ALIAS_TO_CANONICAL:
            corrected_domain = ALIAS_TO_CANONICAL[domain]
            corrected_email = local + "@" + corrected_domain
            is_role = local in ROLE_LOCAL_PARTS
            return "valid", "Normalized regional/alias domain -> " + corrected_domain, corrected_email, is_role

        candidates = edit_distance_1_candidates(domain)
        if len(candidates) == 1:
            corrected_domain = candidates[0]
            corrected_email = local + "@" + corrected_domain
            is_role = local in ROLE_LOCAL_PARTS
            return "valid", "Corrected domain typo -> " + corrected_domain, corrected_email, is_role
        return "excluded", "Domain not a recognized primary provider", email, False

    is_role = local in ROLE_LOCAL_PARTS
    return "valid", "", email, is_role


def completeness_score(row):
    score = 0
    for v in (row.get("Name"), row.get("IC / NRIC"), row.get("Mobile")):
        if v not in (None, ""):
            score += 1
    return score


def main():
    wb = openpyxl.load_workbook(SOURCE_FILE, read_only=True, data_only=True)

    valid_records = OrderedDict()   # email -> best record dict
    excluded_records = []           # list of dicts

    names_seen = defaultdict(set)
    ics_seen = defaultdict(set)
    occurrence_count = defaultdict(int)
    platforms_seen = defaultdict(set)

    total_rows = 0

    for sheet_name in SHEETS:
        if sheet_name not in wb.sheetnames:
            print("WARNING: sheet not found: %s" % sheet_name, file=sys.stderr)
            continue
        ws = wb[sheet_name]
        header = None
        for row in ws.iter_rows(values_only=True):
            if header is None:
                header = [str(h).strip() if h else "" for h in row]
                continue
            total_rows += 1
            record = dict(zip(header, row))
            raw_email = record.get("Email")

            norm_email = normalize_email(raw_email)
            status, reason, final_email, is_role = validate(norm_email)

            if status == "excluded":
                excluded_records.append({
                    "Name": record.get("Name"),
                    "IC / NRIC": record.get("IC / NRIC"),
                    "Mobile": record.get("Mobile"),
                    "Original Email": raw_email,
                    "Platform": sheet_name,
                    "Exclusion Reason": reason,
                })
                continue

            occurrence_count[final_email] += 1
            platforms_seen[final_email].add(sheet_name)

            name = str(record.get("Name") or "").strip().lower()
            if name:
                names_seen[final_email].add(name)
            ic = str(record.get("IC / NRIC") or "").strip().lstrip("'").lower()
            if ic and ic not in TRIVIAL_IC_VALUES:
                ics_seen[final_email].add(ic)

            candidate = {
                "Name": record.get("Name"),
                "IC / NRIC": record.get("IC / NRIC"),
                "Mobile": record.get("Mobile"),
                "Email": final_email,
                "Role-Based": "Yes" if is_role else "",
                "Notes": reason,
            }
            existing = valid_records.get(final_email)
            if existing is None or completeness_score(candidate) > completeness_score(existing):
                valid_records[final_email] = candidate

    # --- Split valid_records into final-valid vs possible-invalid ---
    possible_invalid_records = []
    final_valid_records = OrderedDict()
    for email, rec in valid_records.items():
        reasons = possible_invalid_reasons(email, len(names_seen[email]), len(ics_seen[email]))
        if reasons:
            possible_invalid_records.append({
                "Name": rec["Name"],
                "IC / NRIC": rec["IC / NRIC"],
                "Mobile": rec["Mobile"],
                "Email": email,
                "Platform": ", ".join(sorted(platforms_seen[email])),
                "Occurrences": occurrence_count[email],
                "Distinct Names": len(names_seen[email]),
                "Distinct IC/NRIC": len(ics_seen[email]),
                "Suspicion Reason": "; ".join(reasons),
            })
        else:
            rec["Platform"] = ", ".join(sorted(platforms_seen[email]))
            final_valid_records[email] = rec

    possible_invalid_records.sort(key=lambda r: -r["Occurrences"])

    print("Total rows processed: %d" % total_rows)
    print("Valid unique emails: %d" % len(final_valid_records))
    print("Possible invalid (removed from Valid): %d" % len(possible_invalid_records))
    print("Excluded rows: %d" % len(excluded_records))

    # --- Write Valid file ---
    out_wb = openpyxl.Workbook(write_only=True)
    ws_valid = out_wb.create_sheet("Valid")
    ws_valid.append(["Name", "IC / NRIC", "Mobile", "Email", "Platform", "Role-Based", "Notes"])
    for rec in final_valid_records.values():
        ws_valid.append([
            rec["Name"], rec["IC / NRIC"], rec["Mobile"], rec["Email"],
            rec["Platform"], rec["Role-Based"], rec["Notes"],
        ])
    out_wb.save(VALID_FILE)

    # --- Write Excluded file ---
    exc_wb = openpyxl.Workbook(write_only=True)
    ws_exc = exc_wb.create_sheet("Excluded")
    ws_exc.append(["Name", "IC / NRIC", "Mobile", "Original Email", "Platform", "Exclusion Reason"])
    for rec in excluded_records:
        ws_exc.append([
            rec["Name"], rec["IC / NRIC"], rec["Mobile"], rec["Original Email"],
            rec["Platform"], rec["Exclusion Reason"],
        ])
    exc_wb.save(EXCLUDED_FILE)

    # --- Write Possible Invalid file ---
    pi_wb = openpyxl.Workbook(write_only=True)
    ws_pi = pi_wb.create_sheet("Possible Invalid")
    ws_pi.append([
        "Name", "IC / NRIC", "Mobile", "Email", "Platform",
        "Occurrences", "Distinct Names", "Distinct IC/NRIC", "Suspicion Reason",
    ])
    for rec in possible_invalid_records:
        ws_pi.append([
            rec["Name"], rec["IC / NRIC"], rec["Mobile"], rec["Email"],
            rec["Platform"], rec["Occurrences"], rec["Distinct Names"],
            rec["Distinct IC/NRIC"], rec["Suspicion Reason"],
        ])
    pi_wb.save(POSSIBLE_INVALID_FILE)

    # --- Summaries ---
    reason_counts = {}
    for rec in excluded_records:
        reason_counts[rec["Exclusion Reason"]] = reason_counts.get(rec["Exclusion Reason"], 0) + 1

    print("\nExclusion reason breakdown:")
    for reason, count in sorted(reason_counts.items(), key=lambda x: -x[1]):
        print("  %-45s %8d" % (reason, count))

    pi_reason_counts = defaultdict(int)
    for rec in possible_invalid_records:
        for r in rec["Suspicion Reason"].split("; "):
            key = re.sub(r"\d+", "N", r)
            pi_reason_counts[key] += 1

    print("\nPossible-invalid reason breakdown:")
    for reason, count in sorted(pi_reason_counts.items(), key=lambda x: -x[1]):
        print("  %-70s %8d" % (reason, count))

    role_based_count = sum(1 for r in final_valid_records.values() if r["Role-Based"] == "Yes")
    corrected_count = sum(1 for r in final_valid_records.values() if r["Notes"].startswith("Corrected"))
    aliased_count = sum(1 for r in final_valid_records.values() if r["Notes"].startswith("Normalized"))
    print("\nRole-based valid addresses (flagged, not removed): %d" % role_based_count)
    print("Domain typo auto-corrections applied: %d" % corrected_count)
    print("Regional/alias domains normalized: %d" % aliased_count)


if __name__ == "__main__":
    main()
