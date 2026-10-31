# Ravenkeep MX node

Receives status mails via SMTP and stores them in a local spool for the Ravenkeep web app.

## Intake paths

| Port | Who | Checks |
|---|---|---|
| 25 | external services (public MX) | every message for our domains is accepted and stored completely, then classified: `blocked` (block-list), `pending` (sender not approved yet), `rejected` (approved, but SPF/DKIM does not pass **and** align with the From domain – with reasons), `accepted` |
| 587 (STARTTLS), 465 (TLS) | own devices with SMTP AUTH | valid login required; no further sender checks; recipients restricted to Ravenkeep domains (no relay) |

Every address of the configured domains is accepted – recipients are not pre-registered.

## Components

- `bin/rk-deliver` – Postfix pipe transport: classification, sender authentication, PTR lookup, spool write (exit 75 on internal errors)
- `ravenkeep_mx/senderauth.py` – SPF / DKIM / alignment checks (adapted from SmartMailFlow)
- `ravenkeep_mx/intake.py` – paths, domain lists, spool
- `config/` – Postfix and Dovecot (SASL only) configuration
- `config/fail2ban-jail.conf` – fail2ban jails for SSH and SMTP AUTH (admin networks ignored)
- `deploy.sh` – installs to `/opt/ravenkeep/mx` and applies the configuration (run with sudo)

## Files on the node

| Path | Content |
|---|---|
| `/etc/ravenkeep/allowed-senders` | allow-list: `<domain or address> [envelope\|header]` (domains include subdomains; default `envelope`) |
| `/etc/ravenkeep/blocked-senders` | block-list, same format |
| `/etc/ravenkeep/smtp-users` | SMTP identities, Dovecot passwd-file format (`user:{BLF-CRYPT}hash`) |
| `/etc/ravenkeep/admin-networks` | admin networks never banned by fail2ban (one per line, server-local) |
| `/var/lib/ravenkeep/spool/new/` | `<id>.eml` (original) + `<id>.json` (metadata, written last) |

Approving a sender later lets the web app activate its `pending` messages – nothing has to be re-sent.
The node works without the web app or database; it only needs its local files.

## Tests

    python3 -m unittest discover -s mx/tests
