#!/bin/bash
# Install the web app's systemd units (run with sudo).
set -euo pipefail
SRC="$(cd "$(dirname "$0")" && pwd)/systemd"
install -d -o www-data -g www-data -m 750 /var/lib/ravenkeep-app/run
install -m 644 "$SRC"/*.service "$SRC"/*.timer "$SRC"/*.path /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now ravenkeep-collect.timer ravenkeep-purge.timer ravenkeep-collect.path ravenkeep-webhook.timer
