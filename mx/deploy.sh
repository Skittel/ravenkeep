#!/bin/bash
# Deploy the Ravenkeep MX node from this repository to the local system (run with sudo).
set -euo pipefail
SRC="$(cd "$(dirname "$0")" && pwd)"
DEST=/opt/ravenkeep/mx

id ravenkeep >/dev/null 2>&1 || useradd --system --home-dir /var/lib/ravenkeep --shell /usr/sbin/nologin ravenkeep
install -d -m 755 /opt/ravenkeep "$DEST" "$DEST/bin" "$DEST/ravenkeep_mx"
install -m 755 "$SRC"/bin/rk-* "$DEST/bin/"
install -m 644 "$SRC"/ravenkeep_mx/*.py "$DEST/ravenkeep_mx/"
install -d -m 750 -o ravenkeep -g ravenkeep /var/lib/ravenkeep /var/lib/ravenkeep/spool /var/lib/ravenkeep/spool/tmp /var/lib/ravenkeep/spool/new /var/lib/ravenkeep/attempts
install -d -m 755 /etc/ravenkeep
for f in allowed-sender-domains blocked-sender-domains; do
  [ -f /etc/ravenkeep/$f ] || printf '# One domain per line (subdomains included). Maintained by the Ravenkeep web app.\n' > /etc/ravenkeep/$f
done
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

postfix check
systemctl restart dovecot
systemctl reload postfix
echo "Ravenkeep MX deployed to $DEST"
