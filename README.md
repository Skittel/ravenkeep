# Ravenkeep

Ravenkeep turns a flood of automated status emails into actionable information.

Many systems report their health only by email: backup servers, NAS devices,
uptime monitors, hosting panels and more. Hundreds of such messages per day
usually end up in mail-client rules and get deleted in bulk – and the one
message that matters, or the one that never arrived, is easily missed.

## Features (planned)

- **Mail intake via MailRunner** – a MailRunner receive node
  (https://github.com/Skittel/MailRunner) accepts and analyses the status mails;
  Ravenkeep collects them through the MailRunner API. Sending will use a
  separate MailRunner send node.
- **Sender rules** – allow/block lists per sender domain or address; a message
  is accepted only if SPF or DKIM passes and aligns with the From domain
  (analysis by the MailRunner node). Rejected messages show the reason.
- **Central archive** – original messages (EML) plus metadata, full-text search.
- **Rules and tags** – free, combinable tags (e.g. `customer:x`, `type:nas`,
  `task:verify`, `status:error`) assigned by transparent rules.
- **Address tags** – the recipient address `device_source_location_customer`
  yields customer, location, source and device tags. Only known values are
  used (inventory and existing source tags); addresses with unknown parts are
  listed under "Addresses", where the missing value can be created or the
  address mapped to an existing device.
- **Sender checks and tags** – every allowed sender has its own check (SPF or
  DKIM, SPF and DKIM, SPF only, DKIM only, or explicitly none), aligned with
  the From domain. Its domain can stand for a customer
  (`device_source@customer-domain`) or for a source, with the local part
  naming the device (`server71@plesk.example`). Recipient and sender
  complement each other. An allowed sender can also carry fixed tags
  (e.g. `source:site24x7`), so no extra rule is needed.
- **SMTP logins per component** – devices without a fixed IP send with a
  login; one login per component (e.g. IPMI, hypervisor, backup of one
  server), linked to the device and carrying fixed tags. "Capture tags"
  suggests device and tags once from a scheme-style user name.
- **Missing-report detection** – expected reports per device and task
  (e.g. daily backup, weekly garbage collection) raise an alert when absent.
- **Actions** – e.g. create or update a ticket in Zammad via API, without
  creating duplicate tickets for recurring errors.
- **Mail-client style UI** – filter tree, search, message list, reading pane.
- **Shared devices** – a device has one owning customer (1:1: path, location,
  address scheme) and may be used by further customers (1:n).
- **Rating, handling and notes** – every message has a rating (info, ok,
  warning, error; not rated until content evaluation exists) and a handling
  status (new, open, done, waiting until a date), plus append-only notes with
  author and time.

## Architecture

```
status systems ──SMTP──> MailRunner receive node ──API──> Ravenkeep web app ──> Zammad
                         (Postfix, analysis)              (collector, archive, rules, UI)
```

Access to the receive node: `/etc/ravenkeep/mailrunner.env` (outside the repository)
with `MAILRUNNER_URL` and `MAILRUNNER_TOKEN`; a node row with transport `mailrunner`.

## Tech stack

- Web app: CodeIgniter 4, PHP, MariaDB
- Mail intake: MailRunner receive node (Postfix + analysis), pull API

## Status

Early concept/development phase (version 0.1.0).
Documentation is maintained in the project wiki.

## License

Free for private and commercial use, modification and redistribution
(MIT-style). The only condition beyond the MIT terms: projects that use or
are derived from Ravenkeep must mention Ravenkeep with a link to
https://github.com/Skittel/ravenkeep in their README. See [LICENSE](LICENSE).
