# COLISAP Security Runbook

What to do when something goes wrong, and the routine checks that keep it from going wrong. Commands run on the server in Dokploy → COLISAP → **Terminal** (or `docker exec` into the app container).

## 1. Contacts (fill in and keep current)

| Role | Name | Phone / email |
|---|---|---|
| Breach contact (first call for any security problem) | _____________ | _____________ |
| Data Protection Officer (DPO) of Barbaza MPC | _____________ | _____________ |
| Developer / system administrator | _____________ | _____________ |
| Hosting (VPS) provider support | _____________ | _____________ |

## 2. Suspected breach — first hour

1. **Call the breach contact.** Write down the time you noticed and what you saw.
2. **Stop further damage:** `php artisan down` (maintenance page), then `php artisan colisap:sign-out-everyone --force` (ends every session, including "remember me").
3. **Look before changing anything:** Administration → Audit Logs — filter *Action* by `login_failed`, `login_locked`, `deleted`, `imported_data_deleted`, `member_erased`. Export or screenshot what you find; do not delete logs.
4. **Rotate the secrets** that might be exposed (section 3).
5. **Restore data if it was changed or deleted** (section 4).
6. **Data Privacy Act (RA 10173):** if members' personal data may have been seen or taken, the DPO must notify the **National Privacy Commission within 72 hours** of knowing about it, and the affected members without undue delay (NPC Circular 16-03 — check the current NPC rules).
7. `php artisan up` when it is safe again. Record what happened, what was done and what will prevent it.

## 3. Rotating secrets

| Secret | How | Notes |
|---|---|---|
| Database password | Dokploy → MySQL service → change password; then COLISAP environment `DB_PASSWORD` → redeploy | Use 32+ random characters. |
| `APP_KEY` | `php artisan key:generate --show`, put it in the Dokploy environment, redeploy | Signs everyone out. |
| Monitoring API keys | Super admin account → Administration → Monitoring API → **Rotate** (or Revoke) | Give BMPC ONE the new key right away. |
| `ETXTMO_API_KEY` | eTxtMo dashboard → new key (send-SMS permission only) → Dokploy environment, redeploy | |
| Staff passwords | Super admin account → Administration → Staff Accounts → edit → new password | `php artisan colisap:check-accounts` lists weak or default ones. |

## 4. Restoring from a backup

- COLISAP no longer makes its own backups. Back up the MySQL database in Dokploy (MySQL service → Backups) and restore from there.

## 5. Routine checks

| How often | Check |
|---|---|
| Monthly | `php artisan colisap:check-accounts` — default/weak passwords, test accounts, accounts unused 90+ days |
| Monthly | Staff Accounts (super admin account): deactivate anyone who left (orange "review access" note = unused 90+ days) |
| Monthly | On the server: `bash docker/server-hardening.sh` (check only) — SSH, fail2ban, updates, firewall, exposed ports |
| Every 3 months | Restore drill: restore the newest backup into a staging copy (section 6) and check members and files are there |
| After each deploy | https://developer.mozilla.org/en-US/observatory and https://www.ssllabs.com/ssltest — grades should not drop |
| Weekly (automatic) | GitHub Actions re-runs the tests and `composer audit` every Monday |

## 6. Staging copy (testing without real personal data)

1. In Dokploy, create a second application from the same repository with its **own** MySQL database and its own domain (e.g. `staging.colisap…`); `APP_ENV=staging`, a different `APP_KEY`.
2. Restore a Dokploy database backup into it.
3. Replace all personal details with fake ones: `php artisan colisap:mask-personal-data` (refuses to run on production).

## 7. Database account with only what COLISAP needs

Instead of a database administrator account, COLISAP should connect as its own user (run once as the MySQL root user, then put the user and password in COLISAP's environment):

```sql
CREATE USER 'colisap_app'@'%' IDENTIFIED BY '<32+ random characters>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES ON colisap.* TO 'colisap_app'@'%';
FLUSH PRIVILEGES;
```

(`CREATE`/`ALTER`/`DROP` are needed only because deployments run migrations; a stricter setup runs migrations with a separate account.)

## 8. People rules

- Every person has their **own** account. Shared accounts (like the starter `admin@bmpc.coop`) are deactivated once each administrator has a personal one.
- Never share passwords or one-time codes — not with colleagues, not with "IT support" on the phone. COLISAP never asks for a password by SMS, email or phone.
- A request to reset someone's password is confirmed **in person or by calling them back on a known number** before it is done.
- Use a password manager and turn on two-step login (MFA) for GitHub, Dokploy, the domain registrar, the VPS provider and email.
- Keep the GitHub repository **private**.
- Client credentials (API keys, passwords) are never pasted into chats or kept in plain text files; store them in the password manager.
