"""
Cleansing for 'CLEANING EMAIL LIST.xlsx'.

Reads the first sheet (ID, Client Name, Client IC, Email, Mobile,
Client Code), validates each email, and writes:
  - Valid Emails.xlsx    (clean, with any domain correction noted)
  - Invalid Emails.xlsx  (with the first failing reason)

Corporate, government and education domains are accepted. Usernames
derived from IC numbers or other numeric codes, and internal placeholder
domains, are treated as invalid.

Validation helpers are reused from clean_emails.py.
"""

import json
import os
import re
import subprocess
from collections import defaultdict
from concurrent.futures import ThreadPoolExecutor

import openpyxl

from clean_emails import (
    ALIAS_TO_CANONICAL,
    EMAIL_RE,
    PLACEHOLDER_VALUES,
    _levenshtein_le1,
    is_placeholder_username,
    normalize_email,
)

SOURCE_FILE = "CLEANING EMAIL LIST.xlsx"
VALID_FILE = "Valid Emails.xlsx"
INVALID_FILE = "Invalid Emails.xlsx"

SOURCE_COLUMNS = ["ID", "Client Name", "Client IC", "Email", "Mobile", "Client Code"]

# Internal / system-generated domains that never represent a customer mailbox.
PLACEHOLDER_DOMAINS = {"doc2us.com", "alpro.com", "alpropharmacy.com"}

# Extra local-market typo/alias domains on top of ALIAS_TO_CANONICAL.
EXTRA_ALIASES = {
    "gmail.com.my": "gmail.com",
    "gmail.my": "gmail.com",
    "icloud.com.my": "icloud.com",
    "icloud.my": "icloud.com",
}

# Typo correction is limited to long consumer brand domains. Short domains
# (qq.com, 163.com, live.com) are excluded because a distance-1 neighbour is
# often a genuine, unrelated domain.
TYPO_TARGETS = ["gmail.com", "yahoo.com", "hotmail.com", "outlook.com", "icloud.com"]

# Genuine providers that sit within edit distance 1 of a typo target and
# must never be rewritten (e.g. ymail.com is a real Yahoo domain).
REAL_LOOKALIKE_DOMAINS = {"ymail.com", "mail.com", "email.com", "gmx.com", "hotmail.co", "outlook.co"}

# Digits followed by a short letter suffix, e.g. "780729085150nil", "8501a".
NUMERIC_WITH_SUFFIX_RE = re.compile(r"^\d{6,}[a-z]{1,3}$")

# Provider-enforced minimum username length (dots ignored). Gmail does not
# allow usernames shorter than 6 characters, so "n1@gmail.com" cannot exist.
PROVIDER_MIN_LENGTH = {"gmail.com": 6}

# Five or more of the same letter in a row, e.g. "nillllllll", "ameeeeeee".
LETTER_RUN_RE = re.compile(r"([a-z])\1{4,}")

KEYBOARD_MASH = {"qwerty", "asdfgh", "asdfghjkl", "zxcvbn", "qazwsx", "qweasd", "asdasd"}

# Form-prompt or "no email" phrases typed into the field, e.g.
# "keyinemail2" (key in email), "tiadaemail" (Malay: no email).
PLACEHOLDER_PHRASE_RE = re.compile(
    r"keyin|enteremail|insertemail|youremail|noemail|nomail|notemail|"
    r"noneemail|withoutemail|tiadaemail|takdeemail|xdeemail|tiada"
)

# Staff enter "nil" when a customer has no email, often padded with filler
# ("dasudasdnil", "nilkk", "nasdfasfasdfil"). Genuine names such as Sunil,
# Anil, Danil (delimited) and Nila/Nilam/Nilakash are kept.
NIL_SUFFIX_RE = re.compile(r"nil$")
NIL_NAME_SUFFIX_RE = re.compile(r"(^|[._\-])(s?unil|anil|danil)$")
NIL_PREFIX_RE = re.compile(r"^nil(?!a)")
NIL_SPLIT_RE = re.compile(r"^n[a-z]{2,}il$")

# Major providers whose name appears inside a corrupted domain, e.g.
# "ssgmail.com", "gmail.com.com", "09gmail.com", "yahoo.comgmail.com".
PROVIDER_TOKENS = ("gmail", "hotmail", "yahoo", "ymail")
REAL_PROVIDER_DOMAINS = {
    "gmail.com", "googlemail.com", "ymail.com", "yahoo.com", "yahoo.co.jp",
    "yahoo.co.id", "yahoo.co.in", "yahoo.com.vn", "yahoo.com.ph", "yahoo.co.th",
    "yahoo.fr", "yahoo.de", "yahoo.ca", "yahoo.com.br", "yahoo.com.cn",
    "hotmail.com", "hotmail.co.jp", "hotmail.com.au", "hotmail.com.hk",
    "hotmail.com.tw", "hotmail.de", "hotmail.fr", "hotmail.it", "hotmail.es",
    "hotmail.sg", "hotmail.co.th", "hotmail.com.br",
}

# Country variants of providers that do exist (yahoo.com.my, yahoo.co.nz,
# hotmail.it). These are not rejected outright; the MX check decides.
PROVIDER_CCTLD_RE = re.compile(r"^(yahoo|hotmail|ymail)\.(com?\.)?[a-z]{2}$")

# Gmail-shaped junk on .com: "gmsasail", "gcccmail", "gmfdail", "gmao", "gma".
GMAIL_SHAPED_RE = re.compile(r"^(g[a-z]*ail|gm[a-z]{0,4})$")
NOT_GMAIL_SHAPED = {"gmx", "gmail"}

DISPOSABLE_DOMAINS = {
    "yopmail.com", "mailinator.com", "10minutemail.com", "tempmail.com",
    "temp-mail.org", "trashmail.com", "getnada.com", "dispostable.com",
    "maildrop.cc", "mozmail.com",
    # Guerrilla Mail family (grr.la is used by internal QA test accounts).
    "grr.la", "guerrillamail.com", "guerrillamail.net", "guerrillamail.org",
    "guerrillamail.biz", "guerrillamail.de", "guerrillamail.info",
    "guerrillamailblock.com", "sharklasers.com", "pokemail.net", "spam4.me",
    # Rotating temp-mail.org domains seen in this list.
    "sulfil.com", "nekosan.uk", "yumobiz.com", "itezer.com", "muzibt.com",
    "fuwamofu.com",
}

# Client name marks a QA/test or deleted record, e.g. "TEST MEMBERSHIP",
# "QA TESTING", "(DELETE)lengkee82@gmail.com".
TEST_NAME_RE = re.compile(r"\b(test|testing|tester|qa|dummy|sample)\b|\(delete\)", re.I)

# Client name marks an inactive account: "(CLOSED)", "(Terminated)",
# "(MERGED ACCOUNT)", "MERGE", "(DUPL)", "COMBINE MEMBER", "passed away".
INACTIVE_NAME_RE = re.compile(
    r"\b(closed|terminated?|merged?|dupl?|duplicate)\b|combine member|passed away|"
    r"invalid account",
    re.I,
)

# QA accounts use a doubled country code on an 012 number: 6060123xxxxxx.
TEST_MOBILE_RE = re.compile(r"^6060123\d+$")

# A doubled letter plus digits, e.g. "rr5489707". Kept only when the letter
# matches an initial of the client name (e.g. "qq20010729" for Qiqi).
DOUBLED_LETTER_DIGITS_RE = re.compile(r"^([a-z])\1\d{4,}$")

# Cache of DNS lookups so reruns do not repeat ~2.5k queries.
MX_CACHE_FILE = "domain_mx_cache.json"

# Name servers / mail servers that belong to domain parking or for-sale
# services, e.g. "hotmial.com" (parkingcrew), "ismil.com" (hugedomains),
# "hoail.com" (park-mx.above.com). Ordinary registrar defaults such as
# domaincontrol.com (GoDaddy) and dns-parking.com (Hostinger) are NOT
# parking signals: real companies use them.
PARKING_HOST_RE = re.compile(
    r"parkingcrew|sedoparking|(^|\.)above\.com$|abovedomains|hugedomains|bodis|"
    r"parklogic|namefind|brandbucket|domainrecover|suspended-domain|"
    r"verification-hold|afternic|(^|\.)dan\.com$|undeveloped|cashparking|"
    r"^park-mx\.|^localhost$"
)

# An email shared by this many distinct customers (by name or IC) is a
# counter/staff default rather than a personal mailbox.
SHARED_EMAIL_THRESHOLD = 5
TRIVIAL_IC_VALUES = {"", "0", "1", "-", "na", "n/a", "nil", "none"}

# Generic role mailboxes. Deliverable, so kept valid but flagged.
ROLE_LOCAL_PARTS = {
    "info", "sales", "support", "admin", "administrator", "noreply", "no-reply",
    "contact", "help", "hello", "service", "services", "marketing", "billing",
    "office", "enquiry", "enquiries", "inquiry", "feedback", "webmaster",
    "postmaster", "abuse", "hr", "account", "accounts", "finance", "purchasing",
    "procurement", "customerservice", "cs", "reception", "general", "mail",
    "team", "career", "careers", "jobs", "management", "operation",
    "operations", "it", "helpdesk", "order", "orders",
}

# Apple "Hide My Email" relays look random by design but forward to a real
# inbox, so they are exempt from the gibberish check.
RELAY_DOMAINS = {"privaterelay.appleid.com"}

# Name tokens ignored when relating a username to the client name.
NAME_STOPWORDS = {"bin", "binti", "bte", "bt", "b", "a", "l", "p", "al", "ap", "anak"}


def nil_filler_reason(local):
    if NIL_SUFFIX_RE.search(local) and not NIL_NAME_SUFFIX_RE.search(local):
        return "Filler username ('nil' = no email)"
    if NIL_PREFIX_RE.match(local) or NIL_SPLIT_RE.match(local):
        return "Filler username ('nil' = no email)"
    return None


def is_gibberish_name(name):
    """
    One lowercase Latin word with almost no vowels, e.g. "stsysu", "gsjs".
    Staff enter real names in uppercase, and uppercase short names are often
    genuine initials or spellings ("KLT", "JACYLYN", "GLYNIS"), so only
    lowercase entries are checked.
    """
    if not re.fullmatch(r"[a-z]{3,}", name):
        return False
    vowels = sum(1 for ch in name.lower() if ch in "aeiou")
    return vowels / len(name) < 0.25


def username_relates_to_name(letters, name):
    """
    True when the username shares any 4-letter fragment with a client name
    token, e.g. "naini38naini" / MUSNAINI, "ashikin..." / NORASHIKIN.
    """
    for tok in re.findall(r"[A-Za-z]+", name):
        tok = tok.lower()
        if len(tok) < 4 or tok in NAME_STOPWORDS:
            continue
        if any(tok[i:i + 4] in letters for i in range(len(tok) - 3)):
            return True
    return False


# A real name written twice to get a free username, e.g.
# "mdmosarafsordermdmosarafsorder", "seamiawseamiawseamiaw".
REPEATED_NAME_RE = re.compile(r"^([a-z]{5,})\1+$")


def is_gibberish_username(local, domain, name):
    """
    Keyboard-mash usernames such as "gtgttghghg", "kkkklllaaaa",
    "kdjdkfjffjjxjdjfkdkdkk", "jbjscbjfgj637373". Two shapes:
      - 8+ letters built from very few distinct letters (<= 35%)
      - 10+ letters with no vowel at all
    Usernames containing part of the client name ("tingting...tansiewting"
    for TAN SIEW TING) and Apple relay addresses are exempt. Vowel-dropped
    names ("khrlnzm", "ndrhsh") are shorter than 10 and pass.
    """
    if domain in RELAY_DOMAINS:
        return False
    letters = re.sub(r"[^a-z]", "", local)
    few_letters = len(letters) >= 8 and len(set(letters)) / len(letters) <= 0.35
    no_vowels = len(letters) >= 10 and not re.search(r"[aeiou]", letters)
    if not (few_letters or no_vowels) or REPEATED_NAME_RE.match(letters):
        return False
    return not username_relates_to_name(letters, name)


def record_reason(name, mobile, local, domain=""):
    """Checks that use the client record rather than the email alone."""
    name = str(name or "").strip()
    mobile = re.sub(r"\D", "", str(mobile or ""))
    single_word = len(name.split()) == 1

    if TEST_NAME_RE.search(name):
        return "Test/deleted record (client name)"
    if INACTIVE_NAME_RE.search(name):
        return "Closed/terminated/merged account (client name)"
    if is_gibberish_name(name):
        return "Gibberish client name"
    if single_word and TEST_MOBILE_RE.match(mobile):
        return "Test account (single-word name + dummy mobile)"

    match = DOUBLED_LETTER_DIGITS_RE.match(local)
    if match:
        initials = {tok[0].lower() for tok in re.findall(r"[A-Za-z]+", name)}
        if initials and match.group(1) not in initials:
            return "Doubled-letter + digits username unrelated to client name"
    if is_gibberish_username(local, domain, name):
        return "Gibberish username (keyboard mash)"
    return None


def fake_domain_reason(local, domain):
    labels = domain.split(".")
    if domain in DISPOSABLE_DOMAINS:
        return "Disposable email domain"
    if domain not in REAL_PROVIDER_DOMAINS and not PROVIDER_CCTLD_RE.match(domain) \
            and any(t in domain for t in PROVIDER_TOKENS):
        return "Corrupted provider domain"
    if len(labels) == 2 and labels[1] == "com" and domain not in REAL_PROVIDER_DOMAINS \
            and labels[0] not in NOT_GMAIL_SHAPED and GMAIL_SHAPED_RE.match(labels[0]):
        return "Corrupted provider domain"
    if local == labels[0]:
        return "Username repeats domain name (e.g. no@no.com)"
    return None


def _nslookup(record_type, domain):
    try:
        proc = subprocess.run(
            ["nslookup", "-type=" + record_type, domain + "."],
            capture_output=True, text=True, timeout=20,
        )
    except subprocess.TimeoutExpired:
        return None
    return proc.stdout + proc.stderr


def _failed(out):
    return out is None or "timed out" in out or "Server failed" in out


def _lookup_domain(domain):
    """
    Returns {"status": ..., "mx": [...], "ns": [...]} where status is:
      ok          - publishes a real MX
      nonexistent - NXDOMAIN
      null_mx     - publishes a null MX (RFC 7505: accepts no mail)
      no_mx       - exists but publishes no MX at all (answer confirmed by SOA)
      parked      - name server or mail server belongs to a parking service
      unknown     - lookup failed (timeout / server failure)
    """
    out = _nslookup("mx", domain)
    if _failed(out):
        return {"status": "unknown", "mx": [], "ns": []}
    if "Non-existent domain" in out:
        return {"status": "nonexistent", "mx": [], "ns": []}
    mx = sorted({h.lower().rstrip(".") for h in re.findall(r"mail exchanger = (\S+)", out)})

    ns_out = _nslookup("ns", domain)
    ns = [] if _failed(ns_out) else sorted(
        {h.lower().rstrip(".") for h in re.findall(r"(?:nameserver|primary name server) = (\S+)", ns_out)}
    )

    if any(PARKING_HOST_RE.search(h) for h in mx + ns):
        status = "parked"
    elif mx:
        status = "ok" if any(h not in ("(root)", "") for h in mx) else "null_mx"
    elif "primary name server" in out:
        status = "no_mx"
    else:
        status = "unknown"
    return {"status": status, "mx": mx, "ns": ns}


def lookup_domain(domain):
    for _ in range(3):
        result = _lookup_domain(domain)
        if result["status"] != "unknown":
            return result
    return result


def load_domain_results(domains):
    cache = {}
    if os.path.exists(MX_CACHE_FILE):
        with open(MX_CACHE_FILE, encoding="utf-8") as fh:
            cache = json.load(fh)
    # Entries from the older boolean cache format, or failed lookups, are redone.
    pending = sorted(
        d for d in domains
        if not isinstance(cache.get(d), dict) or cache[d]["status"] == "unknown"
    )
    if pending:
        print("Looking up DNS for %d domains..." % len(pending))
        with ThreadPoolExecutor(max_workers=8) as pool:
            for domain, result in zip(pending, pool.map(lookup_domain, pending)):
                cache[domain] = result
        with open(MX_CACHE_FILE, "w", encoding="utf-8") as fh:
            json.dump(cache, fh, indent=0, sort_keys=True)
    return cache


DOMAIN_STATUS_REASONS = {
    "nonexistent": "Domain does not exist",
    "null_mx": "Domain refuses mail (null MX)",
    "no_mx": "Domain has no mail server (no MX record)",
    "parked": "Parked / for-sale domain",
}


def is_numeric_username(local):
    digits_only = re.sub(r"[._\-+]", "", local)
    return digits_only.isdigit() or bool(NUMERIC_WITH_SUFFIX_RE.match(local))


def junk_username_reason(local):
    """
    Detect filler usernames typed to bypass a required field, e.g.
    "jcjcjcj", "lkklkklkk", "kkkk", "nillllll". A unit must repeat at least
    3 times so genuine reduplicated names ("lingling", "meimei") pass.
    """
    letters = re.sub(r"[^a-z]", "", local)
    if not re.search(r"[a-z0-9]", local):
        return "Username has no letters or digits"
    if letters in KEYBOARD_MASH or letters.startswith(("qwerty", "asdfgh")):
        return "Keyboard-mash username"
    if PLACEHOLDER_PHRASE_RE.search(letters):
        return "Placeholder phrase username (e.g. 'keyinemail')"
    for unit_len in range(1, 5):
        if len(letters) >= 3 * unit_len and (letters[:unit_len] * 40).startswith(letters):
            return "Repeated-pattern username"
    # Two distinct letters stretched over 5+ characters, e.g. "jajjaja", "cncncnn".
    if len(letters) >= 5 and len(set(letters)) <= 2:
        return "Repeated-pattern username"
    if LETTER_RUN_RE.search(letters):
        return "Repeated-character username"
    return None


def correct_domain(domain):
    """Return (corrected_domain, note) or (domain, '') when no change."""
    if domain in EXTRA_ALIASES:
        target = EXTRA_ALIASES[domain]
        return target, "Normalized domain -> " + target
    if domain in ALIAS_TO_CANONICAL:
        target = ALIAS_TO_CANONICAL[domain]
        return target, "Normalized domain -> " + target
    if domain in TYPO_TARGETS or domain in REAL_LOOKALIKE_DOMAINS:
        return domain, ""
    candidates = [t for t in TYPO_TARGETS if _levenshtein_le1(domain, t)]
    if len(candidates) == 1:
        return candidates[0], "Corrected domain typo -> " + candidates[0]
    return domain, ""


def validate(email):
    """Return (is_valid, cleaned_email, reason_or_note)."""
    if email in PLACEHOLDER_VALUES:
        return False, email, "Blank/placeholder value"
    if len(email) > 254:
        return False, email, "Exceeds 254 characters"
    if email.count("@") != 1:
        return False, email, "Invalid syntax (missing/multiple @)"

    local, domain = email.split("@")
    if not local or not domain:
        return False, email, "Missing username or domain"
    if ".." in email or local.startswith(".") or local.endswith(".") \
            or domain.startswith(".") or domain.endswith("."):
        return False, email, "Consecutive dots / malformed punctuation"
    if not EMAIL_RE.match(email):
        return False, email, "Invalid syntax"

    placeholder_reason = is_placeholder_username(local)
    if placeholder_reason:
        return False, email, placeholder_reason
    if is_numeric_username(local):
        return False, email, "Numeric/IC-based username"
    junk_reason = junk_username_reason(local) or nil_filler_reason(local)
    if junk_reason:
        return False, email, junk_reason
    if len(local) <= 2:
        return False, email, "Username too short (<=2 chars)"
    if domain in PLACEHOLDER_DOMAINS:
        return False, email, "Internal/placeholder domain"

    corrected, note = correct_domain(domain)
    domain_reason = fake_domain_reason(local, corrected)
    if domain_reason:
        return False, email, domain_reason
    min_len = PROVIDER_MIN_LENGTH.get(corrected)
    if min_len and len(local.replace(".", "")) < min_len:
        return False, email, "Below %s minimum username length (%d)" % (corrected, min_len)
    return True, local + "@" + corrected, note


def as_text(value):
    """Render numeric IDs/IC/phone values without float or exponent noise."""
    if isinstance(value, float) and value.is_integer():
        return str(int(value))
    return value


def main():
    wb = openpyxl.load_workbook(SOURCE_FILE, read_only=True, data_only=True)
    ws = wb.worksheets[0]

    candidates = []
    invalid_rows = []
    reason_counts = defaultdict(int)
    note_counts = defaultdict(int)
    flag_counts = defaultdict(int)
    names_by_email = defaultdict(set)
    ics_by_email = defaultdict(set)
    total = 0

    def reject(base, reason):
        invalid_rows.append(base + [reason])
        reason_counts[re.sub(r"\d+ distinct", "N distinct", reason)] += 1

    rows = ws.iter_rows(values_only=True)
    next(rows)  # header
    for row in rows:
        if row is None or all(v is None for v in row[:6]):
            continue
        total += 1
        base = [as_text(v) for v in row[:6]]

        ok, cleaned, message = validate(normalize_email(row[3]))
        if ok:
            local, domain = cleaned.split("@")
            rec_reason = record_reason(row[1], row[4], local, domain)
            if rec_reason:
                ok, message = False, rec_reason
        if not ok:
            reject(base, message)
            continue

        candidates.append((base, cleaned, message))
        name = str(row[1] or "").strip().lower()
        if name:
            names_by_email[cleaned].add(name)
        ic = str(as_text(row[2]) or "").strip().lstrip("'").lower()
        if ic not in TRIVIAL_IC_VALUES:
            ics_by_email[cleaned].add(ic)

    # Second pass: shared emails, duplicates, and DNS. A failed lookup
    # (timeout) is kept valid and noted rather than discarded.
    dns = load_domain_results({cleaned.split("@")[1] for _, cleaned, _ in candidates})
    valid_rows = []
    seen = set()
    for base, cleaned, message in candidates:
        local, domain = cleaned.split("@")
        sharers = max(len(names_by_email[cleaned]), len(ics_by_email[cleaned]))
        if sharers >= SHARED_EMAIL_THRESHOLD:
            reject(base, "Shared by %d distinct customers (counter/staff email)" % sharers)
            continue
        if cleaned in seen:
            reject(base, "Duplicate of earlier valid email")
            continue
        status = dns.get(domain, {}).get("status", "unknown")
        if status in DOMAIN_STATUS_REASONS:
            reject(base, DOMAIN_STATUS_REASONS[status])
            continue
        if status == "unknown":
            message = "; ".join(filter(None, [message, "DNS lookup timed out"]))

        seen.add(cleaned)
        flags = "Role address" if local in ROLE_LOCAL_PARTS else ""
        valid_rows.append(base + [cleaned, message, flags])
        if message:
            note_counts[re.sub(r" -> [^;]*", "", message)] += 1
        if flags:
            flag_counts[flags] += 1

    out = openpyxl.Workbook(write_only=True)
    sheet = out.create_sheet("Valid")
    sheet.append(SOURCE_COLUMNS + ["Cleaned Email", "Notes", "Flags"])
    for r in valid_rows:
        sheet.append(r)
    out.save(VALID_FILE)

    out = openpyxl.Workbook(write_only=True)
    sheet = out.create_sheet("Invalid")
    sheet.append(SOURCE_COLUMNS + ["Reason"])
    for r in invalid_rows:
        sheet.append(r)
    out.save(INVALID_FILE)

    print("Total rows processed: %d" % total)
    print("Valid:   %d" % len(valid_rows))
    print("Invalid: %d" % len(invalid_rows))
    print("\nInvalid reason breakdown:")
    for reason, count in sorted(reason_counts.items(), key=lambda x: -x[1]):
        print("  %-60s %8d" % (reason, count))
    print("\nCorrections applied to valid emails:")
    for note, count in sorted(note_counts.items(), key=lambda x: -x[1]):
        print("  %-60s %8d" % (note, count))
    print("\nFlags on valid emails (kept valid):")
    for flag, count in sorted(flag_counts.items(), key=lambda x: -x[1]):
        print("  %-60s %8d" % (flag, count))


if __name__ == "__main__":
    main()
