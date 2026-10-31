"""Shared helpers for the MX node: configuration paths, sender domain lists and the local spool.

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
ATTEMPTS_FILE = os.path.join(DATA_DIR, "attempts", "attempts.jsonl")
ALLOWED_FILE = os.path.join(CONFIG_DIR, "allowed-sender-domains")
BLOCKED_FILE = os.path.join(CONFIG_DIR, "blocked-sender-domains")
NODE_NAME = os.environ.get("RAVENKEEP_NODE", socket.getfqdn())


def read_domain_list(path):
    """One domain per line; '#' starts a comment. Missing file = empty list."""
    try:
        with open(path, encoding="utf-8") as fh:
            return {line.split("#", 1)[0].strip().lower().rstrip(".") for line in fh} - {""}
    except OSError:
        return set()


def domain_in_list(domain, domains):
    """True if the domain or one of its parent domains is listed (statuscake.com covers mail.statuscake.com)."""
    labels = domain.lower().rstrip(".").split(".")
    return any(".".join(labels[i:]) in domains for i in range(len(labels) - 1))


def log_attempt(record):
    """Append metadata of a refused delivery attempt (no message content) as one JSON line."""
    os.makedirs(os.path.dirname(ATTEMPTS_FILE), exist_ok=True)
    record = {"time": time.strftime("%Y-%m-%dT%H:%M:%S%z"), "node": NODE_NAME, **record}
    with open(ATTEMPTS_FILE, "a", encoding="utf-8") as fh:
        fh.write(json.dumps(record, ensure_ascii=False) + "\n")


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
