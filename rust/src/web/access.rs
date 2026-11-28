// SPDX-License-Identifier: LGPL-3.0-only

use super::{admin::escape, plain, setup::redirect, ui::ConsoleSection, ErrorBox, Reply};
use crate::connect;
use hyper::StatusCode;
use rusqlite::{params, Connection, OpenFlags, OptionalExtension, TransactionBehavior};
use sha2::{Digest, Sha256};
use std::{collections::HashMap, fmt::Write, path::Path};

pub(super) const USERS: &str = "/admin/access/users";
pub(super) const TOKENS: &str = "/admin/access/tokens";
pub(super) const PERSONAL_TOKENS: &str = "/admin/profile/tokens";

#[derive(Clone, Copy, PartialEq, Eq)]
pub(super) enum Permission {
    DevicesRead = 0,
    DevicesEdit = 1,
    DevicesDelete = 2,
    Power = 3,
    Deploy = 4,
    Disks = 5,
    Wipe = 6,
    Ansible = 7,
    ActivityRead = 8,
    ActivityClose = 9,
    JobsRead = 10,
    PresetsRead = 12,
    PresetsEdit = 13,
    ImagesRead = 14,
    ImagesEdit = 15,
    SettingsRead = 16,
    SettingsEdit = 17,
    Logs = 18,
    Admin = 62,
}

use Permission::*;
const SCOPES: &[(Permission, &str, &str, bool)] = &[
    (DevicesRead, "devices.read", "Consulter l’inventaire", true),
    (
        DevicesEdit,
        "devices.write",
        "Ajouter ou modifier des appareils",
        true,
    ),
    (
        DevicesDelete,
        "devices.delete",
        "Supprimer des appareils",
        false,
    ),
    (
        Power,
        "devices.power",
        "Commander l’alimentation et le démarrage",
        true,
    ),
    (
        Deploy,
        "deployments.write",
        "Programmer les installations",
        false,
    ),
    (
        Disks,
        "disks.diagnose",
        "Programmer des diagnostics non destructifs",
        false,
    ),
    (
        Wipe,
        "disks.wipe",
        "Effacer des disques et lancer des diagnostics en écriture",
        false,
    ),
    (
        Ansible,
        "ansible.run",
        "Exécuter des opérations Ansible",
        false,
    ),
    (
        ActivityRead,
        "activity.read",
        "Consulter l’activité et les installations",
        true,
    ),
    (
        ActivityClose,
        "activity.close",
        "Clôturer le suivi des opérations",
        false,
    ),
    (JobsRead, "jobs.read", "Consulter l’état des tâches", true),
    (
        PresetsRead,
        "presets.read",
        "Lister les modèles d’installation",
        false,
    ),
    (
        PresetsEdit,
        "presets.write",
        "Consulter les données et gérer les modèles",
        false,
    ),
    (ImagesRead, "images.read", "Consulter les images PXE", true),
    (ImagesEdit, "images.write", "Gérer les images PXE", false),
    (
        SettingsRead,
        "settings.read",
        "Consulter les paramètres généraux",
        false,
    ),
    (
        SettingsEdit,
        "settings.write",
        "Modifier les paramètres généraux",
        false,
    ),
    (Logs, "logs.read", "Consulter les journaux détaillés", false),
];

impl Permission {
    fn bit(self) -> i64 {
        1 << self as u8
    }

    pub(super) fn key(self) -> &'static str {
        SCOPES
            .iter()
            .find(|(scope, ..)| *scope == self)
            .map_or("admin", |(_, key, ..)| key)
    }
}

#[derive(Clone)]
pub(super) struct Account {
    pub(super) id: Option<i64>,
    pub(super) username: String,
    pub(super) admin: bool,
    external: bool,
    permissions: i64,
}

impl Account {
    pub(super) fn allows(&self, permission: Permission) -> bool {
        self.admin || (permission != Admin && self.permissions & permission.bit() != 0)
    }

    pub(super) fn is_external(&self) -> bool {
        self.external
    }

    fn token_permissions(&self) -> i64 {
        SCOPES
            .iter()
            .filter(|(scope, _, _, api)| *api && self.allows(*scope))
            .fold(0, |bits, (scope, ..)| bits | scope.bit())
    }
}

fn read_account(row: &rusqlite::Row<'_>) -> rusqlite::Result<Account> {
    let name: String = row.get(1)?;
    let external: bool = row.get(4)?;
    Ok(Account {
        id: Some(row.get(0)?),
        username: if external {
            name.strip_prefix("sso:").unwrap_or(&name).to_owned()
        } else {
            name
        },
        admin: row.get(2)?,
        permissions: row.get(3)?,
        external,
    })
}

fn stored_account(db: &Connection, id: i64) -> rusqlite::Result<Option<Account>> {
    db.query_row(
        "SELECT id,username,is_admin,permissions,external FROM users WHERE id=?1 AND enabled=1",
        [id],
        read_account,
    )
    .optional()
}

pub(super) fn account(
    db: &Connection,
    username: &str,
    external: bool,
) -> rusqlite::Result<Option<Account>> {
    if !external {
        return db
            .query_row(
                "SELECT id,username,is_admin,permissions,external FROM users
             WHERE username=?1 AND external=0 AND enabled=1",
                [username],
                read_account,
            )
            .optional();
    }
    let account = db
        .query_row(
            "SELECT id,username,is_admin,permissions,external FROM users
         WHERE username=?1 COLLATE BINARY AND external=1 AND enabled=1",
            [format!("sso:{username}")],
            read_account,
        )
        .optional()?;
    if account.is_some() || sso_managed(db)? {
        return Ok(account);
    }
    // Existing proxy deployments keep their policy until an authenticated
    // operator activates account management and becomes its first administrator.
    Ok(Some(Account {
        id: None,
        username: username.to_owned(),
        admin: true,
        external: true,
        permissions: 0,
    }))
}

fn sso_managed(db: &Connection) -> rusqlite::Result<bool> {
    db.query_row(
        "SELECT EXISTS(SELECT 1 FROM users WHERE external=1)",
        [],
        |row| row.get(0),
    )
}

pub(super) fn record(
    db: &Connection,
    actor: &Account,
    action: &str,
    target: &str,
) -> rusqlite::Result<()> {
    let name = if actor.external {
        format!("sso:{}", actor.username)
    } else {
        actor.username.clone()
    };
    db.execute(
        "INSERT INTO access_events(actor,operation,target) VALUES(?1,?2,?3)",
        params![name, action, target],
    )?;
    Ok(())
}

fn token_hash(secret: &str) -> [u8; 32] {
    Sha256::digest(secret.as_bytes()).into()
}

pub(super) fn token_permissions(
    db: &Connection,
    token: &str,
) -> rusqlite::Result<Option<(Account, i64)>> {
    let Some(value) = token.strip_prefix("pxe_") else {
        return Ok(None);
    };
    if value.len() != 64 || !value.bytes().all(|byte| byte.is_ascii_hexdigit()) {
        return Ok(None);
    }
    db.query_row(
        "SELECT u.id,u.username,u.is_admin,u.permissions,u.external,t.scopes
         FROM api_tokens t JOIN users u ON u.id=t.user_id
         WHERE t.secret_hash=?1 AND t.revoked_at IS NULL AND t.expires_at>unixepoch() AND u.enabled=1",
        [token_hash(token).as_slice()],
        |row| {
            let user = read_account(row)?;
            let stored: i64 = row.get(5)?;
            let effective = stored & user.token_permissions();
            Ok((user, effective))
        },
    ).optional()
}

pub(super) fn token_allows(mask: i64, permission: Permission) -> bool {
    permission != Admin && mask & permission.bit() != 0
}

#[derive(Default)]
struct Form {
    fields: HashMap<String, String>,
    rights: i64,
}

impl Form {
    fn parse(body: &[u8]) -> Option<Self> {
        let mut form = Self::default();
        for (key, value) in form_urlencoded::parse(body) {
            if key == "scope" {
                let (scope, ..) = SCOPES.iter().find(|(_, code, ..)| *code == value)?;
                if form.rights & scope.bit() != 0 {
                    return None;
                }
                form.rights |= scope.bit();
            } else if !matches!(
                key.as_ref(),
                "action" | "id" | "username" | "password" | "admin" | "enabled" | "name" | "days"
            ) || form
                .fields
                .insert(key.into_owned(), value.into_owned())
                .is_some()
            {
                return None;
            }
        }
        Some(form)
    }

    fn get(&self, key: &str) -> &str {
        self.fields.get(key).map_or("", String::as_str)
    }

    fn id(&self) -> Option<i64> {
        self.get("id").parse().ok().filter(|id| *id > 0)
    }
}

fn bad(message: &'static str) -> Reply {
    plain(StatusCode::UNPROCESSABLE_ENTITY, message)
}

pub(super) fn denied() -> Reply {
    plain(StatusCode::FORBIDDEN, "Droits insuffisants\n")
}

fn conflict(message: &'static str) -> Reply {
    plain(StatusCode::CONFLICT, message)
}

pub(super) fn users(
    db: &Connection,
    path: &Path,
    actor: &Account,
    form: Option<&[u8]>,
) -> Result<Reply, ErrorBox> {
    if !actor.admin {
        return Ok(denied());
    }
    let Some(body) = form else {
        return users_page(db, actor);
    };
    let Some(form) = Form::parse(body) else {
        return Ok(bad("Formulaire de compte invalide\n"));
    };
    let action = form.get("action");
    let password = form.get("password");
    let hash = if password.is_empty() {
        None
    } else {
        if actor.external || !(12..=72).contains(&password.len()) || password.contains('\0') {
            return Ok(bad(
                "Le mot de passe doit compter entre 12 et 72 octets UTF-8\n",
            ));
        }
        Some(bcrypt::hash(password, bcrypt::DEFAULT_COST)?)
    };
    let mut write = connect(path, OpenFlags::SQLITE_OPEN_READ_WRITE)?;
    let tx = write.transaction_with_behavior(TransactionBehavior::Immediate)?;
    if actor.id.is_none() {
        if action != "activate" || !actor.external || sso_managed(&tx)? {
            return Ok(conflict("Actualisez la gestion des comptes Authentik\n"));
        }
        tx.execute(
            "INSERT INTO users(username,password_hash,is_admin,external)
                    VALUES(?1,'!',1,1)",
            [format!("sso:{}", actor.username)],
        )?;
        record(&tx, actor, "authentik.activate", &actor.username)?;
    } else {
        let Some(current) = stored_account(&tx, actor.id.unwrap())?.filter(|user| user.admin)
        else {
            return Ok(denied());
        };
        let administrator = form.get("admin") == "1";
        match action {
            "create" => {
                let username = form.get("username");
                let valid = !username.is_empty()
                    && username.len() <= 256
                    && username.trim() == username
                    && if current.external {
                        !username
                            .bytes()
                            .any(|byte| byte.is_ascii_control() || byte == b',')
                    } else {
                        username.len() <= 64
                            && username
                                .bytes()
                                .all(|byte| byte.is_ascii_alphanumeric() || b"._-".contains(&byte))
                    };
                if !valid || (!current.external && hash.is_none()) {
                    return Ok(bad("Identifiant ou mot de passe invalide\n"));
                }
                let name = if current.external {
                    format!("sso:{username}")
                } else {
                    username.to_owned()
                };
                if tx.execute(
                    // Old servers ignore roles. Use a separate credential
                    // column so they cannot authenticate a managed account.
                    "INSERT INTO users(username,password_hash,managed_password_hash,is_admin,permissions,external)
                     VALUES(?1,'!',?2,?3,?4,?5) ON CONFLICT(username) DO NOTHING",
                    params![
                        name,
                        hash,
                        administrator,
                        form.rights,
                        current.external
                    ],
                )? != 1
                {
                    return Ok(conflict("Identifiant déjà utilisé\n"));
                }
                record(&tx, &current, "account.create", username)?;
            }
            "update" => {
                let Some(id) = form.id() else {
                    return Ok(bad("Compte invalide\n"));
                };
                let target = tx
                    .query_row(
                        "SELECT username,is_admin,enabled FROM users WHERE id=?1 AND external=?2",
                        params![id, current.external],
                        |row| {
                            Ok((
                                row.get::<_, String>(0)?,
                                row.get::<_, bool>(1)?,
                                row.get::<_, bool>(2)?,
                            ))
                        },
                    )
                    .optional()?;
                let Some((name, was_admin, was_enabled)) = target else {
                    return Ok(plain(StatusCode::NOT_FOUND, "Compte introuvable\n"));
                };
                let enabled = form.get("enabled") == "1";
                if was_admin && was_enabled && (!administrator || !enabled) {
                    let another: bool = tx.query_row(
                        "SELECT EXISTS(SELECT 1 FROM users WHERE external=?1 AND is_admin=1 AND enabled=1 AND id<>?2)",
                        params![current.external, id], |row| row.get(0),
                    )?;
                    if !another {
                        return Ok(conflict("Conservez au moins un administrateur actif\n"));
                    }
                }
                // An administrator inherits every scope. The disabled rights
                // fields are omitted from the browser's form submission.
                tx.execute(
                    "UPDATE users SET is_admin=?2,enabled=?3,
                       permissions=CASE WHEN ?2 THEN permissions ELSE ?4 END,
                       managed_password_hash=COALESCE(?5,managed_password_hash,password_hash),password_hash='!',
                       session_epoch=session_epoch+CASE WHEN NOT ?3 OR ?5 IS NOT NULL THEN 1 ELSE 0 END
                     WHERE id=?1",
                    params![id, administrator, enabled, form.rights, hash],
                )?;
                if !enabled || hash.is_some() {
                    tx.execute("UPDATE api_tokens SET revoked_at=unixepoch() WHERE user_id=?1 AND revoked_at IS NULL", [id])?;
                } else {
                    // Permanently narrow issued grants when rights are removed.
                    // Tokens with no remaining scope are revoked without violating the nonzero constraint.
                    let mask =
                        stored_account(&tx, id)?.map_or(0, |account| account.token_permissions());
                    tx.execute(
                        "UPDATE api_tokens SET revoked_at=unixepoch() WHERE user_id=?1
                                AND revoked_at IS NULL AND (scopes & ?2)=0",
                        params![id, mask],
                    )?;
                    tx.execute("UPDATE api_tokens SET scopes=scopes & ?2 WHERE user_id=?1 AND revoked_at IS NULL",
                               params![id, mask])?;
                }
                record(&tx, &current, "account.update", &name)?;
            }
            _ => return Ok(bad("Action de compte inconnue\n")),
        }
    }
    tx.commit()?;
    Ok(redirect(StatusCode::SEE_OTHER, USERS))
}

pub(super) fn tokens(
    db: &Connection,
    path: &Path,
    actor: &Account,
    form: Option<&[u8]>,
    personal: bool,
) -> Result<Reply, ErrorBox> {
    let Some(id) = actor.id else {
        return Ok(redirect(StatusCode::SEE_OTHER, USERS));
    };
    let route = if personal { PERSONAL_TOKENS } else { TOKENS };
    let section = if personal || !actor.admin {
        ConsoleSection::Tokens
    } else {
        ConsoleSection::Accounts
    };
    let Some(body) = form else {
        return tokens_page(db, actor, route, section);
    };
    let Some(form) = Form::parse(body) else {
        return Ok(bad("Formulaire de jeton invalide\n"));
    };
    let mut write = connect(path, OpenFlags::SQLITE_OPEN_READ_WRITE)?;
    let tx = write.transaction_with_behavior(TransactionBehavior::Immediate)?;
    let Some(actor) = stored_account(&tx, id)? else {
        return Ok(denied());
    };
    let action = form.get("action");
    if action == "revoke" {
        let Some(token) = form.id() else {
            return Ok(bad("Jeton invalide\n"));
        };
        if tx.execute(
            "UPDATE api_tokens SET revoked_at=unixepoch() WHERE id=?1 AND revoked_at IS NULL
                       AND (user_id=?2 OR ?3)",
            params![token, id, actor.admin && !personal],
        )? != 1
        {
            return Ok(plain(StatusCode::NOT_FOUND, "Jeton introuvable\n"));
        }
        record(&tx, &actor, "token.revoke", &token.to_string())?;
        tx.commit()?;
        return Ok(redirect(StatusCode::SEE_OTHER, route));
    }
    if form.rights == 0 || form.rights & !actor.token_permissions() != 0 {
        return Ok(bad("Choisissez des droits d’API dont vous disposez\n"));
    }
    let secret = format!("pxe_{}", crate::random_hex::<32>()?);
    let fingerprint = token_hash(&secret);
    let token = match action {
        "create" => {
            let name = form.get("name").trim();
            let days = form.get("days").parse::<i64>().unwrap_or_default();
            if name.is_empty()
                || name.len() > 100
                || name.chars().any(char::is_control)
                || !(1..=365).contains(&days)
            {
                return Ok(bad(
                    "Renseignez le nom du jeton et une durée de 1 à 365 jours\n",
                ));
            }
            tx.execute(
                "INSERT INTO api_tokens(user_id,name,secret_hash,scopes,expires_at)
                        VALUES(?1,?2,?3,?4,unixepoch()+?5*86400)",
                params![id, name, fingerprint.as_slice(), form.rights, days],
            )?;
            tx.last_insert_rowid()
        }
        "rotate" => {
            let Some(token) = form.id() else {
                return Ok(bad("Jeton invalide\n"));
            };
            if tx.execute("UPDATE api_tokens SET secret_hash=?3,scopes=?4,rotated_at=unixepoch()
                           WHERE id=?1 AND user_id=?2 AND revoked_at IS NULL AND expires_at>unixepoch()",
                params![token,id,fingerprint.as_slice(),form.rights])? != 1 {
                return Ok(plain(StatusCode::NOT_FOUND, "Jeton introuvable ou expiré\n"));
            }
            token
        }
        _ => return Ok(bad("Action de jeton inconnue\n")),
    };
    record(
        &tx,
        &actor,
        if action == "create" {
            "token.create"
        } else {
            "token.rotate"
        },
        &token.to_string(),
    )?;
    tx.commit()?;
    Ok(super::ui::page(
        section,
        "Nouveau secret d’API",
        &format!(
            r#"<section class="panel panel--form"><p>Copiez ce secret maintenant. Il est affiché une seule fois.</p><div class="field">
<label for="token-secret">Jeton d’API</label><textarea id="token-secret" rows="3" readonly>{secret}</textarea></div>
<p><a href="{route}">Retour à la liste des jetons</a></p></section>"#,
        ),
    ))
}

fn checkboxes(
    html: &mut String,
    offered: i64,
    selected: i64,
    account_admin: Option<bool>,
) -> std::fmt::Result {
    let attributes = match account_admin {
        Some(true) => " data-account-rights disabled",
        Some(false) => " data-account-rights",
        None => "",
    };
    let inherited = account_admin == Some(true);
    write!(
        html,
        r#"<fieldset class="permission-fields"{attributes}><legend>Droits</legend>"#
    )?;
    if account_admin.is_some() {
        html.push_str(r#"<p class="permission-inheritance">Le rôle administrateur donne tous les droits. Décochez « Administrateur » pour choisir les droits individuellement.</p>"#);
    }
    for (scope, code, label, _) in SCOPES {
        if offered & scope.bit() == 0 {
            continue;
        }
        let assigned = selected & scope.bit() != 0;
        let fallback = if inherited && assigned {
            " data-assigned"
        } else {
            ""
        };
        let checked = if inherited || assigned {
            " checked"
        } else {
            ""
        };
        write!(
            html,
            r#"<label class="check-label"><input type="checkbox" name="scope" value="{code}"{fallback}{checked}> {label}</label>"#
        )?;
    }
    html.push_str("</fieldset>");
    Ok(())
}

fn account_dialog(
    html: &mut String,
    id: &str,
    account: Option<&str>,
    fields: &str,
) -> std::fmt::Result {
    let (title, action, submit) = if account.is_some() {
        ("Modifier le compte", "update", "Enregistrer")
    } else {
        ("Ajouter un compte", "create", "Créer le compte")
    };
    write!(
        html,
        r##"<dialog class="account-dialog" id="{id}" aria-labelledby="{id}-title">
<form method="post" action="{USERS}">
<input type="hidden" name="action" value="{action}">
<header class="account-dialog-header"><h2 id="{id}-title" tabindex="-1" autofocus>{title}</h2>"##
    )?;
    if let Some(account) = account {
        html.push_str(r#"<p class="muted">"#);
        escape(html, account);
        html.push_str("</p>");
    }
    write!(
        html,
        r##"</header><div class="account-dialog-body form-stack">
<p class="account-dialog-error" role="alert" tabindex="-1" hidden></p>
{fields}</div>
<footer class="account-dialog-actions"><a class="button button--quiet" href="#accounts-list" data-account-close>Annuler</a>
<button type="submit">{submit}</button></footer></form></dialog>"##
    )
}

fn users_page(db: &Connection, actor: &Account) -> Result<Reply, ErrorBox> {
    let mut html = String::new();
    if actor.id.is_none() {
        write!(
            html,
            r#"<section class="panel panel--form"><p>L’activation associe les identités Authentik à des comptes explicites. Votre compte devient le premier administrateur. Ajoutez ensuite les identités à autoriser.</p>
<form method="post" action="{USERS}"><button name="action" value="activate">Activer la gestion des comptes Authentik</button></form></section>"#
        )?;
        return Ok(super::ui::page(
            ConsoleSection::Accounts,
            "Comptes et droits",
            &html,
        ));
    }
    let offered = SCOPES
        .iter()
        .fold(0, |bits, (scope, ..)| bits | scope.bit());
    let username_label = if actor.external {
        "Identifiant Authentik"
    } else {
        "Identifiant"
    };
    write!(
        html,
        r##"<div class="accounts-toolbar">
<a class="button" href="#add-account" aria-haspopup="dialog" data-account-open>Ajouter un compte</a>
<a href="{TOKENS}">Jetons de tous les comptes</a>
</div><ul class="accounts-list" id="accounts-list" aria-label="Comptes">"##
    )?;
    let mut fields = String::new();
    write!(
        fields,
        r#"<div class="field"><label for="new-account">{username_label}</label><input id="new-account" name="username" required maxlength="256" autocomplete="off"></div>"#
    )?;
    if !actor.external {
        fields.push_str(r#"<div class="field"><label for="new-password">Mot de passe initial</label><input id="new-password" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required><small>12 caractères minimum.</small></div>"#);
    }
    fields.push_str(r#"<label class="check-label"><input type="checkbox" name="admin" value="1"> Administrateur</label>"#);
    checkboxes(&mut fields, offered, 0, Some(false))?;
    let mut dialogs = String::new();
    account_dialog(&mut dialogs, "add-account", None, &fields)?;
    let mut rows = db.prepare(
        "SELECT id,username,is_admin,enabled,permissions
                              FROM users WHERE external=?1 ORDER BY username",
    )?;
    let mut rows = rows.query([actor.external])?;
    while let Some(row) = rows.next()? {
        let id: i64 = row.get(0)?;
        let name: String = row.get(1)?;
        let name = if actor.external {
            name.strip_prefix("sso:").unwrap_or(&name)
        } else {
            &name
        };
        let is_admin: bool = row.get(2)?;
        let admin = if is_admin { " checked" } else { "" };
        let active: bool = row.get(3)?;
        let enabled = if active { " checked" } else { "" };
        let (status, tone) = if active {
            ("Actif", " status-badge--success")
        } else {
            ("Désactivé", "")
        };
        let role = if is_admin {
            "Administrateur"
        } else {
            "Accès personnalisé"
        };
        let dialog_id = format!("edit-account-{id}");
        write!(
            html,
            r#"<li class="account-row"><div class="account-identity"><strong id="account-name-{id}">"#
        )?;
        escape(&mut html, name);
        write!(
            html,
            r##"</strong><span class="muted">{role}</span></div>
<span class="status-badge{tone}">{status}</span>
<a class="button button--quiet" href="#{dialog_id}" aria-haspopup="dialog" aria-labelledby="account-edit-{id} account-name-{id}" id="account-edit-{id}" data-account-open>Modifier</a></li>"##
        )?;
        fields.clear();
        write!(
            fields,
            r#"<input type="hidden" name="id" value="{id}">
<label class="check-label"><input type="checkbox" name="enabled" value="1"{enabled}> Compte actif</label>
<label class="check-label"><input type="checkbox" name="admin" value="1"{admin}> Administrateur</label>"#
        )?;
        if !actor.external {
            write!(
                fields,
                r#"<div class="field"><label for="account-password-{id}">Nouveau mot de passe (facultatif)</label><input id="account-password-{id}" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password"></div>"#
            )?;
        }
        checkboxes(&mut fields, offered, row.get(4)?, Some(is_admin))?;
        account_dialog(&mut dialogs, &dialog_id, Some(name), &fields)?;
    }
    html.push_str("</ul>");
    html.push_str(&dialogs);
    let mut events = db.prepare(
        "SELECT datetime(created_at,'unixepoch'),actor,operation,target
                                 FROM access_events ORDER BY id DESC LIMIT 30",
    )?;
    let mut events = events.query([])?;
    html.push_str(r#"<h2>Journal des accès</h2><div class="table-region"><table><thead><tr><th>Date (UTC)</th><th>Compte</th><th>Action</th><th>Cible</th></tr></thead><tbody>"#);
    while let Some(row) = events.next()? {
        html.push_str("<tr>");
        for col in 0..4 {
            html.push_str("<td>");
            escape(&mut html, &row.get::<_, String>(col)?);
            html.push_str("</td>");
        }
        html.push_str("</tr>");
    }
    html.push_str("</tbody></table></div>");
    Ok(super::ui::page(
        ConsoleSection::Accounts,
        "Comptes et droits",
        &html,
    ))
}

fn tokens_page(
    db: &Connection,
    actor: &Account,
    route: &str,
    section: ConsoleSection,
) -> Result<Reply, ErrorBox> {
    let personal = route == PERSONAL_TOKENS;
    let mut html = String::new();
    if personal {
        write!(
            html,
            r#"<nav class="page-links"><a href="{}">Mon profil</a></nav>"#,
            super::profile::ROUTE
        )?;
    }
    html.push_str("<p>Les jetons servent à appeler l’API HTTPS. Chaque jeton dispose de ses propres droits et expire à la date indiquée.</p>");
    let offered = actor.token_permissions();
    if offered != 0 {
        write!(
            html,
            r#"<details class="panel panel--form"><summary>Créer un jeton</summary>
<form class="form-stack permission-form" method="post" action="{route}">
<div class="field"><label for="token-name">Nom du jeton</label><input id="token-name" name="name" maxlength="100" required></div>
<div class="field"><label for="token-days">Durée de validité en jours</label><input id="token-days" name="days" type="number" min="1" max="365" value="30" required></div>"#
        )?;
        checkboxes(&mut html, offered, 0, None)?;
        html.push_str(r#"<div class="form-actions"><button name="action" value="create">Créer le jeton</button></div></form></details>"#);
    }
    let mut rows = db.prepare(
        "SELECT t.id,t.name,t.user_id,u.username,t.scopes,datetime(t.expires_at,'unixepoch'),
                t.expires_at<=unixepoch(),t.revoked_at IS NOT NULL
         FROM api_tokens t JOIN users u ON u.id=t.user_id
         WHERE t.user_id=?1 OR ?2 ORDER BY t.id DESC",
    )?;
    let mut rows = rows.query(params![actor.id, actor.admin && !personal])?;
    let mut found = false;
    while let Some(row) = rows.next()? {
        found = true;
        let id: i64 = row.get(0)?;
        let own = Some(row.get::<_, i64>(2)?) == actor.id;
        let revoked: bool = row.get(7)?;
        let expired: bool = row.get(6)?;
        let status = if revoked {
            "Révoqué"
        } else if expired {
            "Expiré"
        } else {
            "Actif"
        };
        let rights = row.get::<_, i64>(4)?;
        html.push_str(r#"<details class="panel panel--form"><summary>"#);
        escape(&mut html, &row.get::<_, String>(1)?);
        write!(
            html,
            r#" <span class="muted">· {status}</span></summary><p>"#
        )?;
        if !own {
            escape(&mut html, &row.get::<_, String>(3)?);
            html.push_str(" · ");
        }
        html.push_str("Expire le ");
        escape(&mut html, &row.get::<_, String>(5)?);
        html.push_str(" UTC</p><ul>");
        for (scope, _, label, _) in SCOPES {
            if rights & scope.bit() != 0 {
                write!(html, "<li>{label}</li>")?;
            }
        }
        html.push_str("</ul>");
        if own && !revoked && !expired && offered != 0 {
            write!(
                html,
                r#"<form class="form-stack permission-form" method="post" action="{route}">
<input type="hidden" name="id" value="{id}"><p>La rotation invalide le secret précédent et applique les droits sélectionnés. La date d’expiration est conservée.</p>"#
            )?;
            checkboxes(&mut html, offered, rights, None)?;
            html.push_str(r#"<div class="form-actions"><button name="action" value="rotate">Faire tourner le jeton</button></div></form>"#);
        }
        if !revoked && !expired {
            write!(
                html,
                r#"<form class="permission-form" method="post" action="{route}"><input type="hidden" name="id" value="{id}">
<button class="button--danger" name="action" value="revoke">Révoquer le jeton</button></form>"#
            )?;
        }
        html.push_str("</details>");
    }
    if personal && !found {
        html.push_str(if offered == 0 {
            r#"<p class="panel panel--form">Votre compte ne dispose d’aucun droit d’API. Demandez un accès à votre administrateur.</p>"#
        } else {
            r#"<div class="panel panel--form table-empty"><strong>Aucun jeton d’API</strong>Créez un jeton pour autoriser un outil à accéder à l’API avec vos droits.</div>"#
        });
    }
    Ok(super::ui::page(
        section,
        if personal {
            "Mes jetons d’API"
        } else {
            "Jetons d’API"
        },
        &html,
    ))
}
