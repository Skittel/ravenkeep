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


class SharedVectorsTest(unittest.TestCase):
    """Same cases as the PHP tests (webapp/tests/unit/SenderRulesTest.php)."""

    CASES = os.path.join(os.path.dirname(__file__), "..", "..", "tests-shared", "sender_match_cases.json")

    def test_shared_cases(self):
        import json
        with open(self.CASES, encoding="utf-8") as fh:
            cases = json.load(fh)
        for case in cases["address_matches"]:
            with self.subTest(case=case):
                self.assertEqual(intake.address_matches(case["address"], case["pattern"]), case["expected"])
        for case in cases["find_match"]:
            with self.subTest(case=case):
                rules = [tuple(r) for r in case["rules"]]
                self.assertEqual(intake.find_match(rules, case["envelope"], case["header"]), case["expected"])


class ApplyConfigTest(unittest.TestCase):
    """rk-apply-config: validation and installation of config bundles."""

    def setUp(self):
        import hashlib
        import importlib.machinery
        import importlib.util
        import tempfile
        self.hashlib = hashlib
        self.tmp = tempfile.mkdtemp()
        os.environ["RAVENKEEP_DATA_DIR"] = os.path.join(self.tmp, "data")
        os.environ["RAVENKEEP_CONFIG_DIR"] = os.path.join(self.tmp, "etc")
        os.makedirs(os.path.join(self.tmp, "data", "config-incoming"))
        os.makedirs(os.path.join(self.tmp, "etc"))
        import importlib
        importlib.reload(intake)
        path = os.path.join(os.path.dirname(__file__), "..", "bin", "rk-apply-config")
        loader = importlib.machinery.SourceFileLoader("rk_apply_config", path)
        spec = importlib.util.spec_from_loader("rk_apply_config", loader)
        self.mod = importlib.util.module_from_spec(spec)
        loader.exec_module(self.mod)
        self.mod.install = self._install_without_chown

    def tearDown(self):
        import shutil
        shutil.rmtree(self.tmp)
        os.environ.pop("RAVENKEEP_DATA_DIR")
        os.environ.pop("RAVENKEEP_CONFIG_DIR")
        import importlib
        importlib.reload(intake)

    def _install_without_chown(self, name, data):
        with open(os.path.join(intake.CONFIG_DIR, name), "wb") as fh:
            fh.write(data)
        return False  # no reload actions in tests

    def _deliver(self, files, manifest_files=None, bundle_hash="a" * 64):
        incoming = self.mod.INCOMING_DIR
        for name, content in files.items():
            with open(os.path.join(incoming, name), "w", encoding="utf-8") as fh:
                fh.write(content)
        import json
        manifest_files = manifest_files or {n: self.hashlib.sha256(c.encode()).hexdigest() for n, c in files.items()}
        with open(os.path.join(incoming, "manifest.json"), "w", encoding="utf-8") as fh:
            json.dump({"hash": bundle_hash, "files": manifest_files}, fh)

    def _status(self):
        import json
        with open(self.mod.STATUS_FILE, encoding="utf-8") as fh:
            return json.load(fh)

    def test_valid_bundle_is_installed(self):
        self._deliver({"allowed-senders": "statuscake.com envelope\nalerts@plesk.example header\n"})
        self.assertEqual(self.mod.main(), 0)
        with open(os.path.join(intake.CONFIG_DIR, "allowed-senders"), encoding="utf-8") as fh:
            self.assertIn("statuscake.com", fh.read())
        self.assertEqual(self._status()["applied_hash"], "a" * 64)
        self.assertEqual(os.listdir(self.mod.INCOMING_DIR), [])

    def test_invalid_content_keeps_previous_config(self):
        self._deliver({"allowed-senders": "good.example\n"})
        self.mod.main()
        self._deliver({"allowed-senders": "bad pattern here\n"}, bundle_hash="b" * 64)
        self.assertEqual(self.mod.main(), 1)
        with open(os.path.join(intake.CONFIG_DIR, "allowed-senders"), encoding="utf-8") as fh:
            self.assertEqual(fh.read(), "good.example\n")
        status = self._status()
        self.assertEqual(status["applied_hash"], "a" * 64)
        self.assertIn("line 1", status["error"])

    def test_checksum_mismatch_and_unknown_file_are_rejected(self):
        self._deliver({"allowed-senders": "ok.example\n"}, manifest_files={"allowed-senders": "0" * 64})
        self.assertEqual(self.mod.main(), 1)
        self.assertIn("checksum", self._status()["error"])
        self._deliver({"passwd": "x\n"})
        self.assertEqual(self.mod.main(), 1)
        self.assertIn("unknown file", self._status()["error"])

    def test_new_file_validators(self):
        good_hash = "$2y$10$" + "a" * 53
        self.mod.validate_smtp_users(f"# header\nacme.nas01@customer.example.org:{{BLF-CRYPT}}{good_hash}\n")
        for bad in [f"acme@customer.example.org:{good_hash}",                       # missing scheme
                    f"acme@customer.example.org:{{PLAIN}}secret",
                    f"bad user@customer.example.org:{{BLF-CRYPT}}{good_hash}",
                    f"a@x.example:{{BLF-CRYPT}}{good_hash}\na@x.example:{{BLF-CRYPT}}{good_hash}"]:  # duplicate
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                self.mod.validate_smtp_users(bad + "\n")
        self.mod.validate_local_domains("customer.example.org\nexample.org\n")
        for bad in ["", "\n", "# comment\n", "not a domain\n"]:
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                self.mod.validate_local_domains(bad)
        self.mod.validate_admin_networks("# x\n192.0.2.10\n2001:db8::/64\n")
        for bad in ["10.0.0.0/4\n", "example.org\n", "300.1.1.1\n"]:
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                self.mod.validate_admin_networks(bad)

    def test_validator_rejects_bad_patterns(self):
        for bad in ["com", "a@@b.example", "x.example both", "x.example envelope extra", "-bad-.example"]:
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                self.mod.validate_sender_list(bad + "\n")
        self.mod.validate_sender_list("# comment\n\nxn--mnchen-3ya.de\nuser.name+tag@sub.example.org header\n")


if __name__ == "__main__":
    unittest.main()
