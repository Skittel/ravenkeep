"""Unit tests for sender authentication without network access (fixed DNS answers).

Run: python3 -m unittest discover -s mx/tests
"""
import base64
import email
import os
import sys
import unittest

import dkim
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from ravenkeep_mx import intake, senderauth  # noqa: E402
from ravenkeep_mx.dnsclient import StaticDNS  # noqa: E402

KEY = rsa.generate_private_key(public_exponent=65537, key_size=2048)
PRIVATE_PEM = KEY.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.TraditionalOpenSSL,
                                serialization.NoEncryption())
PUBLIC_B64 = base64.b64encode(KEY.public_key().public_bytes(
    serialization.Encoding.DER, serialization.PublicFormat.SubjectPublicKeyInfo)).decode()

DNS = {
    ("monitor.example", "TXT"): ["v=spf1 ip4:192.0.2.10 -all"],
    ("_dmarc.monitor.example", "TXT"): ["v=DMARC1; p=reject"],
    ("sel1._domainkey.monitor.example", "TXT"): [f"v=DKIM1; k=rsa; p={PUBLIC_B64}"],
    ("other.example", "TXT"): ["v=spf1 ip4:198.51.100.7 -all"],
}


def make_message(from_addr="alert@monitor.example", body="Backup OK\r\n", sign_domain=None):
    raw = (f"From: {from_addr}\r\nTo: nas01@customer.ravenkeep.cloud\r\nSubject: Status\r\n"
           f"Message-ID: <1@test>\r\nDate: Sat, 31 Oct 2026 12:00:00 +0000\r\n\r\n{body}").encode()
    if sign_domain:
        raw = dkim.sign(raw, b"sel1", sign_domain.encode(), PRIVATE_PEM,
                        include_headers=[b"from", b"to", b"subject"]) + raw
    return raw


def run(raw, client_ip, mail_from):
    msg = email.message_from_bytes(raw)
    return senderauth.evaluate(StaticDNS(DNS), raw, msg, client_ip, mail_from, "mail.monitor.example")


class SenderAuthTest(unittest.TestCase):
    def test_spf_aligned_is_accepted(self):
        result = run(make_message(), "192.0.2.10", "bounce@monitor.example")
        self.assertEqual(result["verdict"], "accepted")
        self.assertTrue(result["alignment"]["spf_aligned"])

    def test_dkim_aligned_without_spf_is_accepted(self):
        result = run(make_message(sign_domain="monitor.example"), "203.0.113.5", "bounce@other.example")
        self.assertEqual(result["spf"]["result"], "fail")
        self.assertEqual(result["verdict"], "accepted")
        self.assertTrue(result["alignment"]["dkim_aligned"])

    def test_tampered_body_is_rejected_with_reason(self):
        raw = make_message(sign_domain="monitor.example").replace(b"Backup OK", b"Backup FAILED")
        result = run(raw, "203.0.113.5", "bounce@other.example")
        self.assertEqual(result["verdict"], "rejected")
        self.assertTrue(any("DKIM fail" in r for r in result["reasons"]))
        self.assertTrue(any("SPF fail" in r for r in result["reasons"]))

    def test_spf_pass_for_other_domain_is_not_aligned(self):
        result = run(make_message(), "198.51.100.7", "bounce@other.example")
        self.assertEqual(result["verdict"], "rejected")
        self.assertTrue(any("not aligned" in r for r in result["reasons"]))

    def test_missing_from_is_rejected(self):
        raw = b"To: x@customer.ravenkeep.cloud\r\nSubject: no from\r\n\r\nbody\r\n"
        result = run(raw, "192.0.2.10", "bounce@monitor.example")
        self.assertEqual(result["verdict"], "rejected")


class SenderListTest(unittest.TestCase):
    def test_domain_covers_subdomains_only_on_label_boundary(self):
        self.assertTrue(intake.address_matches("a@mail.statuscake.com", "statuscake.com"))
        self.assertTrue(intake.address_matches("a@statuscake.com", "statuscake.com"))
        self.assertFalse(intake.address_matches("a@evilstatuscake.com", "statuscake.com"))
        self.assertFalse(intake.address_matches("a@example.com", "com"))
        self.assertFalse(intake.address_matches("", "statuscake.com"))

    def test_full_address_matches_exactly(self):
        self.assertTrue(intake.address_matches("<Alerts@Plesk.example>", "alerts@plesk.example"))
        self.assertFalse(intake.address_matches("other@plesk.example", "alerts@plesk.example"))

    def test_match_target_selects_envelope_or_header(self):
        entries = [("bounce.example", "envelope"), ("alerts@monitor.example", "header")]
        self.assertEqual(intake.find_match(entries, "x@bounce.example", "")["target"], "envelope")
        self.assertEqual(intake.find_match(entries, "x@other.example", "alerts@monitor.example")["target"], "header")
        self.assertIsNone(intake.find_match(entries, "alerts@monitor.example", "x@bounce.example"))

    def test_list_file_parsing(self):
        import tempfile
        with tempfile.NamedTemporaryFile("w", delete=False) as fh:
            fh.write("# comment\nStatusCake.com\nalerts@plesk.example header  # note\nfoo.example bogus\n\n")
        try:
            self.assertEqual(intake.read_sender_list(fh.name), [("statuscake.com", "envelope"),
                             ("alerts@plesk.example", "header"), ("foo.example", "envelope")])
        finally:
            os.unlink(fh.name)


if __name__ == "__main__":
    unittest.main()
