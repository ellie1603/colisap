#!/usr/bin/env bash
# COLISAP server hardening for the Dokploy VPS (Ubuntu/Debian). Run as root over SSH:
#
#   bash server-hardening.sh            # CHECK ONLY: reports problems, changes nothing
#   bash server-hardening.sh --apply    # fixes what can be fixed safely (asks before each step)
#
# What it covers (security-audit-checklist.md A8/A11/E13):
#   * SSH: key-only login, no root password login (only if a login key is already installed, so you cannot lock yourself out)
#   * fail2ban: blocks addresses that keep guessing SSH passwords
#   * automatic security updates for the operating system
#   * host firewall (ufw): only 22, 80, 443
#   * Docker log rotation (container logs otherwise grow without limit)
#   * reports ports published by Docker containers. These BYPASS ufw: the database (3306) and the Dokploy
#     dashboard (3000) must be closed in Dokploy itself, see the messages at the end.

set -euo pipefail

APPLY=0
if [[ "${1:-}" == "--apply" ]]; then APPLY=1; fi

if [[ $EUID -ne 0 ]]; then
    echo "Run this as root (sudo bash $0 ${1:-})." >&2
    exit 1
fi

ok()   { printf '  \033[32mOK\033[0m    %s\n' "$*"; }
warn() { printf '  \033[33mFIX\033[0m   %s\n' "$*"; }
info() { printf '        %s\n' "$*"; }
ask()  { if [[ $APPLY -ne 1 ]]; then return 1; fi; read -r -p "        Apply: $* [y/N] " reply; [[ "$reply" =~ ^[Yy]$ ]]; }

echo "== SSH"
key_count=$(cat /root/.ssh/authorized_keys /home/*/.ssh/authorized_keys 2>/dev/null | grep -cE '^(ssh-|ecdsa-|sk-)' || true)
password_auth=$(sshd -T 2>/dev/null | awk '/^passwordauthentication/ {print $2}')
root_login=$(sshd -T 2>/dev/null | awk '/^permitrootlogin/ {print $2}')

if [[ "$password_auth" == "no" ]]; then
    ok "SSH password login is off"
elif [[ $key_count -eq 0 ]]; then
    warn "SSH accepts passwords, and NO login key is installed yet."
    info "First add your key from your own PC:  ssh-copy-id root@<server>   then run this script again."
else
    warn "SSH accepts passwords ($key_count login key(s) installed, so key-only login is safe to turn on)."
    if ask "turn off SSH password login"; then
        cat > /etc/ssh/sshd_config.d/99-colisap-hardening.conf <<'CONF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
MaxAuthTries 4
CONF
        if sshd -t; then
            systemctl reload ssh 2>/dev/null || systemctl reload sshd
            ok "SSH is now key-only. KEEP THIS SESSION OPEN and test a new login from another window before closing it."
        else
            rm -f /etc/ssh/sshd_config.d/99-colisap-hardening.conf
            warn "The SSH configuration test failed, so nothing was changed."
        fi
    fi
fi
if [[ "$root_login" == "yes" ]]; then info "Root password login is currently allowed (fixed by the same step)."; fi

echo "== fail2ban (blocks repeated SSH password guesses)"
if systemctl is-active --quiet fail2ban 2>/dev/null; then
    ok "fail2ban is running"
else
    warn "fail2ban is not running"
    if ask "install and start fail2ban for SSH"; then
        apt-get update -qq && apt-get install -y -qq fail2ban
        cat > /etc/fail2ban/jail.d/colisap-sshd.local <<'CONF'
[sshd]
enabled = true
maxretry = 5
findtime = 10m
bantime = 1h
CONF
        systemctl enable --now fail2ban && systemctl restart fail2ban
        ok "fail2ban is running"
    fi
fi

echo "== Automatic security updates"
if dpkg -s unattended-upgrades >/dev/null 2>&1 && grep -q '"1"' /etc/apt/apt.conf.d/20auto-upgrades 2>/dev/null; then
    ok "unattended-upgrades is on"
else
    warn "Operating system security updates are not installed automatically"
    if ask "turn on automatic security updates"; then
        apt-get install -y -qq unattended-upgrades
        printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' > /etc/apt/apt.conf.d/20auto-upgrades
        ok "Automatic security updates are on"
    fi
fi
pending=$(apt list --upgradable 2>/dev/null | grep -c security || true)
if [[ $pending -gt 0 ]]; then warn "$pending security update(s) waiting: run  apt-get upgrade"; else ok "No security updates waiting"; fi

echo "== Host firewall (ufw)"
if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then
    ok "ufw is active"; ufw status | sed 's/^/        /'
else
    warn "No host firewall is active"
    if ask "enable ufw allowing only SSH (22), HTTP (80) and HTTPS (443)"; then
        apt-get install -y -qq ufw
        ufw default deny incoming && ufw default allow outgoing
        ufw allow 22/tcp && ufw allow 80/tcp && ufw allow 443/tcp
        ufw --force enable
        ok "ufw is active"
    fi
fi

echo "== Docker log rotation"
if grep -q '"max-size"' /etc/docker/daemon.json 2>/dev/null; then
    ok "Docker logs are rotated"
elif [[ -s /etc/docker/daemon.json ]]; then
    warn "/etc/docker/daemon.json exists without log limits. Add:  \"log-driver\": \"json-file\", \"log-opts\": {\"max-size\": \"20m\", \"max-file\": \"5\"}"
else
    warn "Docker container logs grow without limit"
    if ask "limit Docker logs to 5 files of 20 MB per container (applies to containers started after the next Docker restart)"; then
        printf '{\n  "log-driver": "json-file",\n  "log-opts": { "max-size": "20m", "max-file": "5" }\n}\n' > /etc/docker/daemon.json
        ok "Saved. It takes effect for each container when it is next recreated (e.g. the next COLISAP deploy)."
    fi
fi

echo "== Ports published by Docker (these bypass ufw)"
published=$(docker ps --format '{{.Names}} {{.Ports}}' 2>/dev/null | grep -oE '[a-zA-Z0-9_.-]+ .*0\.0\.0\.0:[0-9]+' || true)
if echo "$published" | grep -qE '0\.0\.0\.0:3306|:::3306'; then
    warn "The MySQL database (port 3306) is open to the internet."
    info "Dokploy → the MySQL service → Advanced/External Credentials → remove the External Port, then Save/Redeploy."
    info "Then change the database password to a long random one (32+ characters) and update DB_PASSWORD for COLISAP."
else
    ok "Database port 3306 is not published"
fi
if echo "$published" | grep -qE '0\.0\.0\.0:3000'; then
    warn "The Dokploy dashboard is open on plain-HTTP port 3000 (its password travels unencrypted)."
    info "Dokploy → Settings → Server/Web Server: assign a domain with HTTPS for the dashboard; afterwards close"
    info "port 3000 (Dokploy docs: 'Disable direct access to port 3000' / remove the published port)."
else
    ok "Dokploy dashboard port 3000 is not published"
fi
docker ps --format '        {{.Names}}: {{.Ports}}' 2>/dev/null | grep '0.0.0.0' || true

echo
if [[ $APPLY -eq 0 ]]; then echo "Check only — nothing was changed. Run with --apply to fix the items marked FIX."; fi
