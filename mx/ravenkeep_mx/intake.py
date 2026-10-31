"""Shared helpers for the MX node: configuration paths, sender lists and the local spool.

Sender lists (/etc/ravenkeep/allowed-senders, /etc/ravenkeep/blocked-senders), one entry per line:
    <pattern> [envelope|header]
pattern is a domain (also matches subdomains) or a full address; the second column selects
which sender is compared: envelope sender (MAIL FROM, default) or the visible From header.

Spool layout (one entry per message and recipient):
    <spool>/tmp/   files being written
    <spool>/new/   complete entries: <id>.eml (original message) and <id>.json (metadata)
The .json file is renamed last, so its presence marks a complete entry for the collector.
"""
import json
import os
import socket
import time
import uuid

CONFIG_DIR = os.environ.get("RAVENKEEP_CONFIG_DIR", "/etc/ravenkeep")
DATA_DIR = os.environ.get("RAVENKEEP_DATA_DIR", "/var/lib/ravenkeep")
SPOOL_DIR = os.path.join(DATA_DIR, "spool")
ALLOWED_FILE = os.path.join(CONFIG_DIR, "allowed-senders")
BLOCKED_FILE = os.path.join(CONFIG_DIR, "blocked-senders")
NODE_NAME = os.environ.get("RAVENKEEP_NODE", socket.getfqdn())
MATCH_TARGETS = ("envelope", "header")


def read_sender_list(path):
    """Return a list of (pattern, target). Missing file = empty list; invalid targets fall back to envelope."""
    entries = []
    try:
        with open(path, encoding="utf-8") as fh:
            for line in fh:
                parts = line.split("#", 1)[0].split()
                if not parts:
                    continue
                target = parts[1].lower() if len(parts) > 1 and parts[1].lower() in MATCH_TARGETS else "envelope"
                entries.append((parts[0].lower().rstrip("."), target))
    except OSError:
        pass
    return entries


def address_matches(address, pattern):
    """Full address: exact match. Domain: the address domain or one of its parents equals the pattern."""
    address = (address or "").lower().strip("<>")
    if not address:
        return False
    if "@" in pattern:
        return address == pattern
    domain = address.rsplit("@", 1)[-1].rstrip(".")
    labels = domain.split(".")
    return any(".".join(labels[i:]) == pattern for i in range(len(labels) - 1))


def find_match(entries, envelope_sender, header_from):
    """First list entry matching the selected sender, or None."""
    for pattern, target in entries:
        sender = envelope_sender if target == "envelope" else header_from
        if address_matches(sender, pattern):
            return {"pattern": pattern, "target": target}
    return None


def _write_synced(path, data):
    with open(path, "wb") as fh:
        fh.write(data)
        fh.flush()
        os.fsync(fh.fileno())


def spool_message(raw_bytes, metadata):
    """Store the original message and its metadata durably. Returns the entry id."""
    entry_id = f"{time.strftime('%Y%m%dT%H%M%S')}-{uuid.uuid4().hex[:12]}"
    tmp_dir, new_dir = os.path.join(SPOOL_DIR, "tmp"), os.path.join(SPOOL_DIR, "new")
    os.makedirs(tmp_dir, exist_ok=True)
    os.makedirs(new_dir, exist_ok=True)
    metadata = {"id": entry_id, "node": NODE_NAME, "received_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
                **metadata}
    eml_tmp, json_tmp = os.path.join(tmp_dir, entry_id + ".eml"), os.path.join(tmp_dir, entry_id + ".json")
    _write_synced(eml_tmp, raw_bytes)
    _write_synced(json_tmp, json.dumps(metadata, ensure_ascii=False, indent=1).encode())
    os.rename(eml_tmp, os.path.join(new_dir, entry_id + ".eml"))
    os.rename(json_tmp, os.path.join(new_dir, entry_id + ".json"))
    dir_fd = os.open(new_dir, os.O_RDONLY)
    try:
        os.fsync(dir_fd)
    finally:
        os.close(dir_fd)
    return entry_id
