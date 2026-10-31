# Ravenkeep

Ravenkeep turns a flood of automated status emails into actionable information.

Many systems report their health only by email: backup servers, NAS devices,
uptime monitors, hosting panels and more. Hundreds of such messages per day
usually end up in mail-client rules and get deleted in bulk – and the one
message that matters, or the one that never arrived, is easily missed.

## Features (planned)

- **Dedicated mail intake** – two lightweight SMTP/MX nodes accept status mails
  via authenticated submission (per-device identities) or public MX with an
  allow-list of sender domains. No open relay, no forwarding.
- **Sender authentication** – SPF, DKIM and DMARC alignment are checked.
  Rejected messages are shown with the reason for rejection.
- **Central archive** – original messages (EML) plus metadata, full-text search.
- **Rules and tags** – free, combinable tags (e.g. `customer:x`, `type:nas`,
  `task:verify`, `status:error`) assigned by transparent rules.
- **Missing-report detection** – expected reports per device and task
  (e.g. daily backup, weekly garbage collection) raise an alert when absent.
- **Actions** – e.g. create or update a ticket in Zammad via API, without
  creating duplicate tickets for recurring errors.
- **Mail-client style UI** – filter tree, search, message list, reading pane.

## Architecture

```
status systems ──SMTP──> MX node 1 ┐
                         MX node 2 ┴──> Ravenkeep web app ──> Zammad
                     (local Maildir)    (collector, archive, rules, UI)
```

## Tech stack

- Web app: CodeIgniter 4, PHP, MariaDB
- MX nodes: Postfix, sender authentication checks in Python

## Status

Early concept/development phase (version 0.1.0).
Documentation is maintained in the project wiki.

## License

TBD
