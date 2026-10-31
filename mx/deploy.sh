#!/bin/bash
# Deploy the Ravenkeep MX node from this repository to the local system (run with sudo).
set -euo pipefail
SRC="$(cd "$(dirname "$0")" && pwd)"
DEST=/opt/ravenkeep/mx

id ravenkeep >/dev/null 2>&1 || useradd --system --home-dir /var/lib/ravenkeep --shell /usr/sbin/nologin ravenkeep
install -d -m 755 /opt/ravenkeep "$DEST" "$DEST/bin" "$DEST/ravenkeep_mx"
install -m 755 "$SRC"/bin/rk-* "$DEST/bin/"
install -m 644 "$SRC"/ravenkeep_mx/*.py "$DEST/ravenkeep_mx/"
install -d -m 750 -o ravenkeep -g ravenkeep /var/lib/ravenkeep /var/lib/ravenkeep/spool /var/lib/ravenkeep/spool/tmp
# new/: the collector (group ravenkeep) reads and deletes entries
install -d -m 2770 -o ravenkeep -g ravenkeep /var/lib/ravenkeep/spool/new
# config-incoming/: the web app (group ravenkeep) delivers config bundles here, rk-apply-config installs them
install -d -m 2770 -o ravenkeep -g ravenkeep /var/lib/ravenkeep/config-incoming
chmod g+r /var/lib/ravenkeep/spool/new/* 2>/dev/null || true
# Local collector (web app on the same server) runs as www-data
if id www-data >/dev/null 2>&1; then usermod -aG ravenkeep www-data; fi
install -d -m 755 /etc/ravenkeep
# systemd units for rk-apply-config
install -m 644 "$SRC"/config/systemd/rk-apply-config.* /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now rk-apply-config.path rk-apply-config.timer

for f in allowed-senders blocked-senders; do
  [ -f /etc/ravenkeep/$f ] || printf '# <domain or address> [envelope|header]  (domain includes subdomains, default envelope)\n# Maintained by the Ravenkeep web app.\n' > /etc/ravenkeep/$f
done
rm -f /opt/ravenkeep/mx/bin/rk-policy /etc/ravenkeep/allowed-sender-domains /etc/ravenkeep/blocked-sender-domains
[ -f /etc/ravenkeep/smtp-users ] || : > /etc/ravenkeep/smtp-users
chown root:dovecot /etc/ravenkeep/smtp-users; chmod 640 /etc/ravenkeep/smtp-users

# Postfix main.cf
grep -vE '^\s*(#|$)' "$SRC/config/postfix-main.cf" | while IFS= read -r line; do postconf -e "$line"; done
# Postfix master.cf: replace managed block, disable stock submission/submissions entries
MC=/etc/postfix/master.cf
sed -i '/^# --- ravenkeep begin/,/^# --- ravenkeep end/d' "$MC"
postconf -M# submission/inet submissions/inet 2>/dev/null || true
cat "$SRC/config/postfix-master.cf" >> "$MC"

# Dovecot (SASL only)
[ -f /etc/dovecot/dovecot.conf.dist ] || cp /etc/dovecot/dovecot.conf /etc/dovecot/dovecot.conf.dist
install -m 644 "$SRC/config/dovecot.conf" /etc/dovecot/dovecot.conf
doveconf -n >/dev/null

# fail2ban (SSH + SMTP AUTH brute force)
if command -v fail2ban-client >/dev/null; then
  ADMIN=$( [ -f /etc/ravenkeep/admin-networks ] && grep -vE '^\s*(#|$)' /etc/ravenkeep/admin-networks | tr '\n' ' ' )
  sed "s|^ignoreip = .*|& ${ADMIN}|" "$SRC/config/fail2ban-jail.conf" > /etc/fail2ban/jail.d/ravenkeep.conf
  systemctl restart fail2ban
fi

postfix check
systemctl restart dovecot
systemctl reload postfix
echo "Ravenkeep MX deployed to $DEST"
