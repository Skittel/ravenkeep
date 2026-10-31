#!/bin/bash
# Install the web app's systemd units (run with sudo).
set -euo pipefail
SRC="$(cd "$(dirname "$0")" && pwd)/systemd"
install -m 644 "$SRC"/*.service "$SRC"/*.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now ravenkeep-collect.timer ravenkeep-purge.timer
