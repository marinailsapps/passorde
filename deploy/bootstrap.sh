#!/bin/sh
# First-time server setup (docs/RUNBOOK.md "Server bootstrap"). Run as root on
# Debian or Ubuntu; safe to run again: every step checks first and never
# overwrites a file someone tuned by hand.
#
#   scp deploy/bootstrap.sh .env.production.example root@<host>:/root/
#   ssh root@<host> 'DEPLOY_PUBKEY="ssh-ed25519 AAAA… deploy" REGISTRY=ghcr.io REGISTRY_USER=<user> REGISTRY_TOKEN=… sh /root/bootstrap.sh'
#
# What it does:
#   1. Docker CE + compose plugin from Docker's apt repository
#   2. ufw: deny incoming except SSH (the port sshd really listens on), 80, 443 (tcp + udp)
#   3. fail2ban jail for sshd (journal, sshd-session aware)
#   4. unattended-upgrades for security updates, never rebooting
#   5. the "deploy" user (docker group, your deploy key), allowed by sshd's AllowUsers
#   6. APP_DIR (/srv/passorde): .env (from .env.production.example, deploy:www-data 640), deploy/, docker/caddy/{,sites/}, restore/
#   7. docker login to your image registry for the deploy user with a read-only token
#
# Environment: DEPLOY_USER (deploy), DEPLOY_PUBKEY (the public key you deploy with),
# REGISTRY (ghcr.io), REGISTRY_USER and REGISTRY_TOKEN (pull only), APP_DIR (/srv/passorde), DRY_RUN=1 to only
# print what would change.
set -eu

DEPLOY_USER=${DEPLOY_USER:-deploy}
APP_DIR=${APP_DIR:-/srv/passorde}
REGISTRY=${REGISTRY:-ghcr.io}
DRY_RUN=${DRY_RUN:-0}
here=$(cd "$(dirname "$0")" && pwd)

say() {
	echo "bootstrap: $*"
}

# Runs a command, or prints it with DRY_RUN=1.
run() {
	if [ "$DRY_RUN" = 1 ]; then
		echo "  would run: $*"
	else
		"$@"
	fi
}

# Writes stdin to a file unless it already exists (hand-tuned files win).
install_file() {
	path=$1
	mode=$2
	if [ -e "$path" ]; then
		say "keeping existing $path"
		cat >/dev/null
		return
	fi
	if [ "$DRY_RUN" = 1 ]; then
		echo "  would write $path:"
		sed 's/^/    /'
		return
	fi
	mkdir -p "$(dirname "$path")"
	cat > "$path"
	chmod "$mode" "$path"
	say "wrote $path"
}

if [ "$(id -u)" -ne 0 ] && [ "$DRY_RUN" != 1 ]; then
	echo "bootstrap: run as root" >&2
	exit 1
fi

# shellcheck disable=SC1091 # present on every Debian and Ubuntu
. /etc/os-release
case "${ID:-}" in
	debian | ubuntu) ;;
	*)
		echo "bootstrap: Debian or Ubuntu only (found ${ID:-unknown})" >&2
		exit 1
		;;
esac

ssh_port=$(sshd -T 2>/dev/null | awk '$1 == "port" { print $2; exit }')
ssh_port=${ssh_port:-22}
say "${PRETTY_NAME:-$ID}, sshd on port ${ssh_port}"

export DEBIAN_FRONTEND=noninteractive
run apt-get update -qq
run apt-get install -y -qq ca-certificates curl ufw fail2ban unattended-upgrades

# 1. Docker
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
	say "docker $(docker --version | awk '{print $3}' | tr -d ,) already installed"
else
	say "installing Docker CE"
	run install -m 0755 -d /etc/apt/keyrings
	run curl -fsSL "https://download.docker.com/linux/${ID}/gpg" -o /etc/apt/keyrings/docker.asc
	run chmod a+r /etc/apt/keyrings/docker.asc
	install_file /etc/apt/sources.list.d/docker.sources 0644 <<EOF
Types: deb
URIs: https://download.docker.com/linux/${ID}
Suites: ${VERSION_CODENAME}
Components: stable
Signed-By: /etc/apt/keyrings/docker.asc
EOF
	run apt-get update -qq
	run apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi
run systemctl enable --now docker

# 2. Firewall. Docker publishes ports through its own rules (bypassing ufw):
# compose.prod.yml only publishes Caddy's 80/443.
run ufw default deny incoming
run ufw default allow outgoing
run ufw allow "${ssh_port}/tcp"
run ufw allow 80/tcp
run ufw allow 443/tcp
run ufw allow 443/udp
run ufw --force enable

# 3. fail2ban: OpenSSH 10 logs failures from sshd-session, which Debian's default misses.
install_file /etc/fail2ban/jail.d/passorde.local 0644 <<EOF
[sshd]
enabled = true
port = ${ssh_port}
backend = systemd
journalmatch = _SYSTEMD_UNIT=ssh.service + _COMM=sshd + _COMM=sshd-session
maxretry = 3
findtime = 10m
bantime = 1h
EOF
run systemctl enable --now fail2ban
run systemctl restart fail2ban

# 4. Security updates every day, never an automatic reboot.
install_file /etc/apt/apt.conf.d/20auto-upgrades 0644 <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
install_file /etc/apt/apt.conf.d/52passorde-unattended 0644 <<'EOF'
Unattended-Upgrade::Automatic-Reboot "false";
EOF

# 5. Deploy user: runs docker compose for GitHub Actions, nothing else.
if id "$DEPLOY_USER" >/dev/null 2>&1; then
	say "user ${DEPLOY_USER} exists"
else
	run useradd --create-home --shell /bin/bash "$DEPLOY_USER"
fi
run usermod -aG docker "$DEPLOY_USER"
if [ -n "${DEPLOY_PUBKEY:-}" ]; then
	keys="/home/${DEPLOY_USER}/.ssh/authorized_keys"
	if [ -f "$keys" ] && grep -qF "$DEPLOY_PUBKEY" "$keys"; then
		say "deploy key already authorised"
	else
		run install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/${DEPLOY_USER}/.ssh"
		if [ "$DRY_RUN" = 1 ]; then
			echo "  would append the deploy key to $keys"
		else
			printf '%s\n' "$DEPLOY_PUBKEY" >> "$keys"
			chown "$DEPLOY_USER:$DEPLOY_USER" "$keys"
			chmod 600 "$keys"
		fi
	fi
else
	say "DEPLOY_PUBKEY not set: add the CI public key to /home/${DEPLOY_USER}/.ssh/authorized_keys yourself"
fi

# sshd only admits listed users (AllowUsers in /etc/ssh/sshd_config.d/): add the deploy user.
allow_file=$(grep -lE '^[[:space:]]*AllowUsers' /etc/ssh/sshd_config.d/*.conf 2>/dev/null | head -n 1 || true)
if [ -n "$allow_file" ]; then
	if grep -E '^[[:space:]]*AllowUsers' "$allow_file" | grep -qw "$DEPLOY_USER"; then
		say "sshd AllowUsers already lists ${DEPLOY_USER}"
	elif [ "$DRY_RUN" = 1 ]; then
		echo "  would add ${DEPLOY_USER} to AllowUsers in ${allow_file} and reload ssh"
	else
		cp "$allow_file" "${allow_file}.before-bootstrap"
		sed -i "s/^\([[:space:]]*AllowUsers.*\)$/\1 ${DEPLOY_USER}/" "$allow_file"
		if sshd -t; then
			systemctl reload ssh
			say "added ${DEPLOY_USER} to AllowUsers in ${allow_file} (keep this session open until 'ssh ${DEPLOY_USER}@…' works)"
		else
			mv "${allow_file}.before-bootstrap" "$allow_file"
			echo "bootstrap: sshd -t failed, AllowUsers left unchanged" >&2
			exit 1
		fi
	fi
fi

# 6. Application directory. deploy.yml copies compose.prod.yml, docker/caddy/
# and deploy/ here on every deploy; .env is written once and edited by hand.
run install -d -m 750 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$APP_DIR" "$APP_DIR/deploy" "$APP_DIR/docker" "$APP_DIR/docker/caddy" "$APP_DIR/docker/caddy/sites"
run install -d -m 700 -o root -g root "$APP_DIR/restore"
if [ -f "$APP_DIR/.env" ]; then
	say "keeping existing $APP_DIR/.env"
elif [ -f "$here/.env.production.example" ]; then
	# Group www-data (uid 33, also the app containers' user) may read it; nobody else.
	run install -m 640 -o "$DEPLOY_USER" -g www-data "$here/.env.production.example" "$APP_DIR/.env"
	say "created $APP_DIR/.env from .env.production.example: fill it in (docs/RUNBOOK.md \"Production .env\")"
else
	say "copy .env.production.example next to this script to create $APP_DIR/.env"
fi

# 7. Pull access to your images (a token that can only read them).
if [ -n "${REGISTRY_USER:-}" ] && [ -n "${REGISTRY_TOKEN:-}" ]; then
	if [ "$DRY_RUN" = 1 ]; then
		echo "  would run: docker login ${REGISTRY} -u ${REGISTRY_USER} (as ${DEPLOY_USER})"
	else
		printf '%s' "$REGISTRY_TOKEN" | su "$DEPLOY_USER" -c "docker login '${REGISTRY}' -u '${REGISTRY_USER}' --password-stdin" >/dev/null
		say "logged ${DEPLOY_USER} in to ${REGISTRY} as ${REGISTRY_USER}"
	fi
else
	say "REGISTRY_USER/REGISTRY_TOKEN not set: run 'docker login ${REGISTRY}' as ${DEPLOY_USER} yourself if your images are private"
fi

say "done. Next: fill in ${APP_DIR}/.env, then the first deploy (docs/RUNBOOK.md \"First deploy\")."
