"""Sender authentication for mail received on the public MX: SPF (RFC 7208),
DKIM (RFC 6376) and DMARC-style alignment (RFC 7489).

Ravenkeep accepts a message from an unauthenticated sender only if SPF or DKIM
passes AND is aligned with the visible From domain (decision E-002: messages
that fail are stored but marked as rejected, with the reason).

Adapted from SmartMailFlow (https://github.com/Skittel/smartmailflow).
"""
import email.utils
import re

import dkim
import dns.name
import spf

PSL_FILE = "/usr/share/publicsuffix/public_suffix_list.dat"


def domain_of(address):
    """Domain part of an address (lower case, without trailing dot)."""
    address = (address or "").strip().strip("<>")
    if "@" in address:
        address = address.rsplit("@", 1)[1]
    return address.lower().rstrip(".")


# --------------------------------------------------------------------------- SPF
def check_spf(dnsc, client_ip, mail_from, helo):
    """SPF check with pyspf, DNS via dnsc. With a null sender the HELO identity is checked."""
    def lookup(name, qtype, tcpfallback=True, timeout=30):
        answers, err = dnsc.query(name, qtype)
        if err in ("timeout", "servfail") or (err or "").startswith("error:"):
            raise spf.TempError(f"DNS {err} {name}/{qtype}")
        out = []
        for answer in answers:
            if qtype == "MX":
                pref, host = answer.split(None, 1)
                out.append(((name, qtype), (int(pref), dns.name.from_text(host))))
            elif qtype in ("TXT", "SPF"):
                out.append(((name, qtype), [answer.encode()]))
            elif qtype == "PTR":
                out.append(((name, qtype), answer.rstrip(".")))
            else:
                out.append(((name, qtype), answer))
        return out

    saved = spf.DNSLookup
    spf.DNSLookup = lookup
    try:
        query = spf.query(i=client_ip, s=mail_from or "", h=helo or "unknown", querytime=20)
        result, _code, explanation = query.check()
    except Exception as exc:  # noqa: BLE001
        result, explanation = "temperror", str(exc)
    finally:
        spf.DNSLookup = saved
    domain = domain_of(mail_from) if mail_from else (helo or "").lower().rstrip(".")
    return {"result": result, "identity": "mailfrom" if mail_from else "helo",
            "domain": domain, "explanation": explanation}


# --------------------------------------------------------------------------- DKIM
def tag_list(txt):
    """Parse a DKIM/DMARC tag list ("k=v; k2=v2") into a dict."""
    tags = {}
    for part in (txt or "").split(";"):
        if "=" in part:
            key, value = part.split("=", 1)
            tags[key.strip().lower()] = re.sub(r"\s+", "", value)
    return tags


def check_dkim(dnsc, raw_bytes, msg):
    """Verify every DKIM signature. Returns a list of {domain, selector, result, reason}."""
    def dnsfunc(name, timeout=5):
        answers, _err = dnsc.query(name.decode() if isinstance(name, bytes) else name, "TXT")
        return answers[0].encode() if answers else b""

    signatures = []
    for idx, header in enumerate(msg.get_all("DKIM-Signature", [])):
        tags = tag_list(str(header).replace("\r", "").replace("\n", ""))
        sig = {"domain": tags.get("d", "").lower(), "selector": tags.get("s", ""),
               "result": "permerror", "reason": ""}
        if not (sig["domain"] and sig["selector"]):
            sig["reason"] = "selector or domain missing"
        else:
            try:
                ok = dkim.DKIM(raw_bytes).verify(idx=idx, dnsfunc=dnsfunc)
                sig.update(result="pass" if ok else "fail",
                           reason="signature verified" if ok else "signature invalid")
            except dkim.DKIMException as exc:
                sig.update(result="fail" if "body hash mismatch" in str(exc) else "permerror",
                           reason=str(exc))
            except Exception as exc:  # noqa: BLE001
                sig.update(result="temperror", reason=str(exc))
        signatures.append(sig)
    return signatures


# --------------------------------------------------------------------------- Alignment / DMARC
_psl = None


def _load_psl():
    global _psl
    if _psl is None:
        rules, exceptions, wildcards = set(), set(), set()
        try:
            with open(PSL_FILE, encoding="utf-8") as fh:
                for line in fh:
                    line = line.strip()
                    if not line or line.startswith("//"):
                        continue
                    rule = line.split()[0].lower()
                    if rule.startswith("!"):
                        exceptions.add(rule[1:])
                    elif rule.startswith("*."):
                        wildcards.add(rule[2:])
                    else:
                        rules.add(rule)
        except OSError:
            pass
        _psl = (rules, exceptions, wildcards)
    return _psl


def org_domain(domain):
    """Organizational domain according to the Public Suffix List (default rule: suffix = TLD)."""
    rules, exceptions, wildcards = _load_psl()
    labels = domain.lower().rstrip(".").split(".")
    suffix_len = 1
    for i in range(len(labels)):
        candidate = ".".join(labels[i:])
        if candidate in exceptions:
            suffix_len = len(labels) - i - 1
            break
        if candidate in rules:
            suffix_len = len(labels) - i
            break
        if i + 1 < len(labels) and ".".join(labels[i + 1:]) in wildcards:
            suffix_len = len(labels) - i
            break
    if len(labels) <= suffix_len:
        return ".".join(labels)
    return ".".join(labels[-(suffix_len + 1):])


def aligned(domain_a, domain_b, mode="r"):
    """Strict: identical domains. Relaxed: same organizational domain."""
    a, b = domain_a.lower().rstrip("."), domain_b.lower().rstrip(".")
    return a == b if mode == "s" else org_domain(a) == org_domain(b)


def dmarc_record(dnsc, from_domain):
    """DMARC tags of the From domain or its organizational domain: (tags, error)."""
    for name in dict.fromkeys([from_domain, org_domain(from_domain)]):
        answers, err = dnsc.query(f"_dmarc.{name}", "TXT")
        if err in ("timeout", "servfail") or (err or "").startswith("error:"):
            return {}, err
        records = [a for a in answers if a.strip().lower().startswith("v=dmarc1")]
        if len(records) == 1:
            return tag_list(records[0]), None
    return {}, None


def header_from_domains(msg):
    """Domains of all addresses in the From header(s)."""
    addresses = email.utils.getaddresses([str(h) for h in msg.get_all("From", [])])
    return [domain_of(addr) for _name, addr in addresses if "@" in addr]


# --------------------------------------------------------------------------- Verdict
def evaluate(dnsc, raw_bytes, msg, client_ip, mail_from, helo):
    """Full sender check for one message received on the public MX.

    Returns {"verdict": "accepted"|"rejected", "reasons": [...], "spf": ..., "dkim": [...],
    "from_domain": ..., "alignment": {...}}. Temporary DNS errors are reported as
    verdict "rejected" with a "temporary" reason; the caller may decide to retry.
    """
    reasons = []
    spf_res = check_spf(dnsc, client_ip, mail_from, helo)
    dkim_res = check_dkim(dnsc, raw_bytes, msg)

    from_domains = header_from_domains(msg)
    from_domain = from_domains[0] if len(set(from_domains)) == 1 else None
    result = {"verdict": "rejected", "reasons": reasons, "spf": spf_res, "dkim": dkim_res,
              "from_domain": from_domain, "alignment": {}}
    if not from_domain:
        reasons.append("From header missing or ambiguous")
        return result

    tags, err = dmarc_record(dnsc, from_domain)
    if err:
        reasons.append(f"temporary: DMARC lookup {err}")
    aspf, adkim = tags.get("aspf", "r"), tags.get("adkim", "r")

    spf_ok = spf_res["result"] == "pass" and aligned(spf_res["domain"], from_domain, aspf)
    dkim_ok = any(s["result"] == "pass" and aligned(s["domain"], from_domain, adkim) for s in dkim_res)
    result["alignment"] = {"spf_aligned": spf_ok, "dkim_aligned": dkim_ok, "aspf": aspf, "adkim": adkim,
                           "dmarc_policy": tags.get("p")}

    if spf_ok or dkim_ok:
        result["verdict"] = "accepted"
        return result

    # Explain why neither SPF nor DKIM qualifies
    if spf_res["result"] != "pass":
        reasons.append(f"SPF {spf_res['result']} for {spf_res['domain'] or 'empty sender'}")
    else:
        reasons.append(f"SPF pass for {spf_res['domain']}, but not aligned with From domain {from_domain}")
    if not dkim_res:
        reasons.append("no DKIM signature")
    for sig in dkim_res:
        if sig["result"] != "pass":
            reasons.append(f"DKIM {sig['result']} ({sig['domain'] or '?'}): {sig['reason']}")
        else:
            reasons.append(f"DKIM pass for {sig['domain']}, but not aligned with From domain {from_domain}")
    return result
