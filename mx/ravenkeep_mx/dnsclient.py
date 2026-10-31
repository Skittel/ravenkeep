"""DNS access with a per-message cache.

Interface: query(name, rtype) -> (answers, error)
  answers  list of strings: A/AAAA = address, MX = "10 host.", TXT = joined text,
           CNAME/PTR = target name with trailing dot
  error    None | "nxdomain" | "nodata" | "servfail" | "timeout" | "error:<type>"

Adapted from SmartMailFlow (https://github.com/Skittel/smartmailflow).
"""
import dns.exception
import dns.resolver
import dns.reversename


def classify(exc):
    """Map a dnspython exception to a short error code."""
    if isinstance(exc, dns.resolver.NXDOMAIN):
        return "nxdomain"
    if isinstance(exc, dns.resolver.NoAnswer):
        return "nodata"
    if isinstance(exc, dns.resolver.NoNameservers):
        return "servfail"
    if isinstance(exc, (dns.exception.Timeout, dns.resolver.LifetimeTimeout)):
        return "timeout"
    return f"error:{exc.__class__.__name__}"


def _rdata_text(rtype, rdata):
    if rtype == "TXT":
        return b"".join(rdata.strings).decode(errors="replace")
    if rtype in ("A", "AAAA"):
        return rdata.address
    if rtype == "MX":
        return f"{rdata.preference} {rdata.exchange.to_text()}"
    if rtype in ("CNAME", "PTR"):
        return rdata.target.to_text()
    return rdata.to_text()


class DNSClient:
    """System resolver with a cache that lives for one message."""

    def __init__(self, lifetime=5.0):
        self.lifetime = lifetime
        self.cache = {}

    def _lookup(self, name, rtype):
        try:
            answer = dns.resolver.resolve(name, rtype, lifetime=self.lifetime)
            return [_rdata_text(rtype, r) for r in answer], None
        except Exception as exc:  # noqa: BLE001 – every DNS failure becomes an error code
            return [], classify(exc)

    def query(self, name, rtype):
        key = (name.lower().rstrip("."), rtype.upper())
        if key not in self.cache:
            self.cache[key] = self._lookup(*key)
        return self.cache[key]

    def reverse(self, ip):
        """PTR names of an IP address (without trailing dot); empty list if none or invalid."""
        try:
            name = dns.reversename.from_address(ip).to_text()
        except Exception:  # noqa: BLE001
            return []
        return [n.rstrip(".") for n in self.query(name, "PTR")[0]]


class StaticDNS:
    """Fixed answers for tests: {("name", "TXT"): ["..."]}; missing entries are NXDOMAIN."""

    def __init__(self, records):
        self.records = {(n.lower().rstrip("."), t.upper()): v for (n, t), v in records.items()}

    def query(self, name, rtype):
        key = (name.lower().rstrip("."), rtype.upper())
        if key in self.records:
            value = self.records[key]
            return (value, None) if isinstance(value, list) else ([], value)
        return [], "nxdomain"

    def reverse(self, ip):
        return self.records.get((ip, "PTR"), [])
