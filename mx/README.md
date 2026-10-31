# Ravenkeep MX node

Receives status mails via SMTP and stores them in a local spool for the Ravenkeep web app.

## Intake paths

| Port | Who | Checks |
|---|---|---|
| 25 | external services (public MX) | envelope sender domain must be on the allow-list (`451` if unknown, `550` if blocked, metadata logged); after DATA: SPF or DKIM must pass **and** align with the From domain – otherwise the message is stored but marked `rejected` with reasons |
| 587 (STARTTLS), 465 (TLS) | own devices with SMTP AUTH | valid login required; no further sender checks; recipients restricted to Ravenkeep domains (no relay) |

Every address of the configured domains is accepted – recipients are not pre-registered.

## Components

- `bin/rk-policy` – Postfix policy service (sender domain allow/block list, logs refused attempts)
- `bin/rk-deliver` – Postfix pipe transport: sender authentication + spool write (exit 75 on internal errors)
- `ravenkeep_mx/senderauth.py` – SPF / DKIM / alignment checks (adapted from SmartMailFlow)
- `ravenkeep_mx/intake.py` – paths, domain lists, spool
- `config/` – Postfix and Dovecot (SASL only) configuration
- `deploy.sh` – installs to `/opt/ravenkeep/mx` and applies the configuration (run with sudo)

## Files on the node

| Path | Content |
|---|---|
| `/etc/ravenkeep/allowed-sender-domains` | allow-list (one domain per line, subdomains included) |
| `/etc/ravenkeep/blocked-sender-domains` | block-list |
| `/etc/ravenkeep/smtp-users` | SMTP identities, Dovecot passwd-file format (`user:{BLF-CRYPT}hash`) |
| `/var/lib/ravenkeep/spool/new/` | `<id>.eml` (original) + `<id>.json` (metadata, written last) |
| `/var/lib/ravenkeep/attempts/attempts.jsonl` | refused attempts (metadata only) |

## Tests

    python3 -m unittest discover -s mx/tests
