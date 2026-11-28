// SPDX-License-Identifier: LGPL-3.0-only

use super::{
    access::{self, Permission},
    auth_proxy::TrustedProxies,
    bytes, plain,
    sessions::{self, Sessions},
    single,
    ui::{self, ConsoleSection},
    Reply,
};
use crate::{configured_database, connect};
use base64::{engine::general_purpose::STANDARD, Engine};
use hyper::{
    body::Bytes,
    header::{ACCEPT, AUTHORIZATION, LOCATION, WWW_AUTHENTICATE},
    http::request::Parts,
    Response, StatusCode,
};
use rusqlite::{params, Connection, OpenFlags};
use std::{
    collections::HashMap,
    net::{IpAddr, Ipv4Addr},
    path::Path,
    sync::{Arc, Mutex},
    time::{Duration, Instant},
};
use tokio::{sync::Semaphore, task};

const PAGE_SIZE: usize = 100;
const DEVICE_STATES: &[(&str, &str)] = &[
    ("unknown", "Inconnu"),
    ("inventorying", "Inventaire en cours"),
    ("discovered", "Détecté"),
    ("configured", "Configuré"),
    ("installing", "Installation en cours"),
    ("installed", "Installé"),
    ("provisioned", "Provisionné"),
    ("failed", "Échec"),
    ("wiping", "Effacement en cours"),
    ("wiped", "Effacé"),
    ("testing", "Diagnostic en cours"),
    ("tested", "Diagnostic terminé"),
];

pub(super) enum Action {
    Devices,
    Overview(Option<Ipv4Addr>, Option<Vec<crate::boot_networks::Network>>),
    Device(super::devices::DeviceRoute, Option<Bytes>),
    Linux(
        i64,
        std::path::PathBuf,
        super::linux_boot::RhelRepositories,
        Option<Bytes>,
    ),
    Register(Option<Bytes>),
    Bulk(super::devices::bulk::Route, Option<Bytes>),
    Images(super::images::Route, Option<Bytes>, Option<String>),
    Presets(Option<Bytes>),
    Preset(super::presets::Route, Option<Bytes>),
    PullPresets,
    Jobs(super::jobs::View),
    Windows(Arc<super::winpe::Editions>, Option<Bytes>),
    Activity(super::activity::View, Arc<crate::monitoring::Catalog>),
    Settings(super::settings::Route, Option<Bytes>),
    ConsolePort(super::console_port::Visit),
    Setup(String, Option<Bytes>),
    Certificates(Arc<super::certificates::Certificates>, Option<Bytes>),
    Acme(Arc<super::certificates::Certificates>, Bytes),
    Users(Option<Bytes>),
    Tokens(Option<Bytes>, bool),
    Profile(Option<Bytes>),
    Notifications,
}

impl Action {
    fn permission(&self, method: &hyper::Method) -> Option<Permission> {
        use super::{
            activity::View as Activity, devices::bulk::Route as Bulk, jobs::View as Job,
            presets::Route as Preset, settings::Route as Setting,
        };
        use Permission::*;
        let read = matches!(*method, hyper::Method::GET | hyper::Method::HEAD);
        let select = |view, edit| if read { view } else { edit };
        Some(match self {
            Self::Devices => DevicesRead,
            Self::Overview(..) => SettingsRead,
            Self::Device(route, form) => route.permission(method, form.as_deref()),
            Self::Linux(..) => Deploy,
            Self::Register(..) => DevicesEdit,
            Self::Bulk(Bulk::Delete, _) => DevicesDelete,
            Self::Bulk(..) => Deploy,
            Self::Images(super::images::Route::RedHat, ..) => ImagesEdit,
            Self::Images(..) | Self::Windows(..) => select(ImagesRead, ImagesEdit),
            Self::Presets(..) => select(PresetsRead, PresetsEdit),
            Self::Preset(
                Preset::New
                | Preset::Import
                | Preset::Detail(_)
                | Preset::Snapshot(_)
                | Preset::Push(_)
                | Preset::Duplicate(_)
                | Preset::Options(_),
                _,
            )
            | Self::PullPresets => PresetsEdit,
            Self::Jobs(Job::Retry(_)) => Admin,
            Self::Jobs(Job::Detail(_) | Job::Poll(_)) => Logs,
            Self::Jobs(Job::List | Job::Status(_) | Job::Latest(_)) => JobsRead,
            Self::Activity(Activity::ForceComplete(_) | Activity::CancelInstallation(_), _) => {
                ActivityClose
            }
            Self::Activity(
                Activity::Legacy(..)
                | Activity::Installation(_)
                | Activity::Wipe(_)
                | Activity::DiskTest(_)
                | Activity::Download(..),
                _,
            ) => Logs,
            Self::Activity(
                Activity::List
                | Activity::Fragment
                | Activity::DeviceTasks(_)
                | Activity::DeviceProgress(_)
                | Activity::Task(_)
                | Activity::Checklist(_),
                _,
            ) => ActivityRead,
            Self::Settings(Setting::General | Setting::Dns | Setting::Ipxe, _) => {
                select(SettingsRead, SettingsEdit)
            }
            Self::Settings(..)
            | Self::ConsolePort(..)
            | Self::Setup(..)
            | Self::Certificates(..)
            | Self::Acme(..)
            | Self::Users(..) => Admin,
            Self::Tokens(..) | Self::Profile(_) | Self::Notifications => return None,
        })
    }
}

enum Identity {
    Basic(String, Vec<u8>),
    Session(String),
    Forwarded(String),
}

pub(super) struct Console {
    attempts: Mutex<HashMap<IpAddr, (Instant, u8)>>,
    jobs: Semaphore,
    proxies: TrustedProxies,
    sessions: Sessions,
}

impl Console {
    pub(super) fn new() -> Self {
        Self {
            attempts: Mutex::new(HashMap::new()),
            jobs: Semaphore::new(2),
            proxies: TrustedProxies::from_environment(),
            sessions: Sessions::from_environment(),
        }
    }

    pub(super) fn trusted_proxy(&self, peer: IpAddr) -> bool {
        self.proxies.contains(peer)
    }

    pub(super) async fn login(
        &self,
        request: &Parts,
        peer: IpAddr,
        config: &Path,
        form: Option<Bytes>,
    ) -> Reply {
        let query = request.uri.query().unwrap_or_default();
        let query_next = form_urlencoded::parse(query.as_bytes())
            .find(|(key, _)| key == "next")
            .map(|(_, value)| value);
        let next = sessions::local_next(query_next.as_deref().unwrap_or_default());
        match std::env::var("AUTH_MODE").unwrap_or_default().as_str() {
            "" | "local" => {}
            "authentik" => {
                return if form.is_none() && self.proxies.identity(peer, &request.headers).is_some()
                {
                    super::setup::redirect(StatusCode::FOUND, next)
                } else {
                    sso_required()
                };
            }
            _ => return auth_unavailable(),
        }
        let Some(form) = form else {
            return sessions::login_page(next, None, StatusCode::OK);
        };
        let mut username = None;
        let mut password = None;
        let mut target = None;
        for (key, value) in form_urlencoded::parse(&form) {
            let field = match key.as_ref() {
                "username" => &mut username,
                "password" => &mut password,
                "next" => &mut target,
                _ => continue,
            };
            if field.replace(value).is_some() {
                return plain(StatusCode::BAD_REQUEST, "Invalid login form\n");
            }
        }
        let next = sessions::local_next(target.as_deref().unwrap_or(next));
        let username = username.as_deref().map(str::trim).unwrap_or_default();
        let password = password.as_deref().unwrap_or_default();
        if username.is_empty() || username.len() > 256 || password.is_empty() {
            return sessions::login_page(
                next,
                Some("Saisissez votre identifiant et votre mot de passe."),
                StatusCode::UNPROCESSABLE_ENTITY,
            );
        }
        let Ok(_permit) = self.jobs.try_acquire() else {
            return plain(StatusCode::SERVICE_UNAVAILABLE, "Busy\n");
        };
        let Some(window) = self.reserve_attempt(peer) else {
            return rate_limited();
        };
        let config = config.to_owned();
        let sessions = self.sessions.clone();
        let current_token = sessions::token(&request.headers);
        let username = username.to_owned();
        let password = password.to_owned();
        let next = next.to_owned();
        let result = task::spawn_blocking(move || {
            // Exercise bcrypt for unknown accounts as well as existing accounts.
            const UNKNOWN_ACCOUNT_HASH: &str =
                "$2y$12$BivNwfeaFgD7FobF2xFxFeacHAMld9a7bYBP0yCpqXk/Ve6YOFioi";
            let db_path = configured_database(&config)?;
            let db = connect(&db_path, OpenFlags::SQLITE_OPEN_READ_WRITE).ok()?;
            let credential = sessions::credential(&db, &username).ok()?;
            let verified = bcrypt::verify(password, credential.as_ref().map_or(UNKNOWN_ACCOUNT_HASH, |(hash, _)| hash))
                .unwrap_or(false);
            let Some((hash, epoch)) = credential.filter(|_| verified) else {
                return Some(sessions::login_page(
                    &next,
                    Some("Identifiants incorrects."),
                    StatusCode::UNAUTHORIZED,
                ));
            };
            if db.execute(
                "UPDATE users SET last_login_at=CURRENT_TIMESTAMP WHERE username=?1
                 AND COALESCE(managed_password_hash,password_hash)=?2 AND session_epoch=?3 AND enabled=1 AND external=0",
                params![username, hash, epoch],
            ).ok()? != 1 {
                return Some(unauthorized());
            }
            let account = access::account(&db, &username, false).ok()??;
            let next = if matches!(next.as_str(), "/admin/" | "/admin/inventaire") && !account.allows(Permission::DevicesRead) {
                ui::home(&account)
            } else if !account.admin || crate::setting(&db, "wizard_completed").ok()? == "1" {
                &next
            } else {
                "/init/network"
            };
            let token = sessions
                .issue(username, &hash, epoch, current_token.as_deref())
                .ok()?;
            Some(sessions::cookie(&token, next))
        })
        .await;
        if result.as_ref().is_ok_and(|result| {
            result
                .as_ref()
                .is_some_and(|reply| reply.status() == StatusCode::SEE_OTHER)
        }) {
            self.refund_attempt(peer, window);
        }
        result.ok().flatten().unwrap_or_else(auth_unavailable)
    }

    pub(super) async fn logout(&self, headers: &hyper::HeaderMap) -> Reply {
        match std::env::var("AUTH_MODE").unwrap_or_default().as_str() {
            "" | "local" => {}
            "authentik" => return sso_required(),
            _ => return auth_unavailable(),
        }
        if let Some(token) = sessions::token(headers) {
            let sessions = self.sessions.clone();
            if !task::spawn_blocking(move || sessions.remove(&token))
                .await
                .is_ok_and(|result| result.is_ok())
            {
                return auth_unavailable();
            }
        }
        sessions::cookie("", "/login")
    }

    pub(super) async fn serve(
        &self,
        request: &Parts,
        peer: IpAddr,
        config: &Path,
        action: Action,
    ) -> Reply {
        if request.uri.path() == "/admin/" {
            return Response::builder()
                .status(StatusCode::FOUND)
                .header(LOCATION, "/admin/inventaire")
                .body(bytes(""))
                .expect("valid admin redirect");
        }
        let identity = match std::env::var("AUTH_MODE").unwrap_or_default().as_str() {
            "" | "local" => {
                if request.headers.contains_key(AUTHORIZATION) {
                    let Some((username, password)) =
                        single(&request.headers, AUTHORIZATION.as_str()).and_then(credentials)
                    else {
                        return unauthorized();
                    };
                    Identity::Basic(username, password)
                } else if let Some(token) = sessions::token(&request.headers) {
                    Identity::Session(token)
                } else {
                    return sessions::login_required(request);
                }
            }
            "authentik" => {
                let Some(user) = self.proxies.identity(peer, &request.headers) else {
                    return sso_required();
                };
                Identity::Forwarded(user.to_owned())
            }
            _ => return auth_unavailable(),
        };
        let Ok(_permit) = self.jobs.try_acquire() else {
            return plain(StatusCode::SERVICE_UNAVAILABLE, "Busy\n");
        };
        let changing_password = matches!(&action, Action::Profile(Some(_)));
        let window = if changing_password || matches!(identity, Identity::Basic(..)) {
            let Some(window) = self.reserve_attempt(peer) else {
                return rate_limited();
            };
            Some(window)
        } else {
            None
        };
        let sessions = self.sessions.clone();
        let profile_token = match &identity {
            Identity::Session(token) if changing_password => Some(token.clone()),
            _ => None,
        };
        let missing_session =
            matches!(identity, Identity::Session(_)).then(|| sessions::login_required(request));
        let page = request
            .uri
            .query()
            .and_then(|query| {
                form_urlencoded::parse(query.as_bytes()).find_map(|(key, value)| {
                    (key == "page").then(|| value.parse::<u32>().ok()).flatten()
                })
            })
            .unwrap_or(1)
            .max(1);
        let config = config.to_owned();
        let path = request.uri.path().to_owned();
        let method = request.method.clone();
        let query = request.uri.query().unwrap_or_default().to_owned();
        let wants_json = request
            .headers
            .get(ACCEPT)
            .is_some_and(|value| value == "application/json");
        let reply = task::spawn_blocking(move || {
            let mut authenticated = false;
            let reply = (|| {
                let db_path = configured_database(&config)?;
                let db = connect(&db_path, OpenFlags::SQLITE_OPEN_READ_ONLY).ok()?;
                let (username, external) = match identity {
                    Identity::Basic(username, password) => {
                        if !sessions::password_hash(&db, &username)
                            .ok()?
                            .is_some_and(|hash| bcrypt::verify(password, &hash).unwrap_or(false))
                        {
                            return Some(unauthorized());
                        }
                        (username, false)
                    }
                    Identity::Session(token) => {
                        let Some(username) = sessions.user(&db, &token).ok()? else {
                            return missing_session;
                        };
                        (username, false)
                    }
                    Identity::Forwarded(username) => (username, true),
                };
                authenticated = true;
                let Some(account) = access::account(&db, &username, external).ok()? else {
                    return Some(access::denied());
                };
                let _navigation = ui::navigation_for(&account);
                if action
                    .permission(&method)
                    .is_some_and(|permission| !account.allows(permission))
                    && !(account.allows(Permission::ImagesEdit)
                        && matches!(&action, Action::Jobs(view) if view.is_redhat_media(&db).unwrap_or(false)))
                {
                    return Some(if path.starts_with("/api/") {
                        access::denied()
                    } else {
                        let mut response = ui::page(
                            ConsoleSection::Tokens,
                            "Accès réservé",
                            &format!(r#"<p>Votre compte ne dispose pas du droit nécessaire.</p><p><a href="{}">Revenir à vos rubriques</a></p>"#, ui::home(&account)),
                        );
                        *response.status_mut() = StatusCode::FORBIDDEN;
                        response
                    });
                }
                if let Action::Users(form) = &action {
                    return access::users(&db, &db_path, &account, form.as_deref()).ok();
                }
                if matches!(action, Action::Notifications) {
                    return super::notifications::feed(&db, &account).ok();
                }
                if let Action::Overview(address, networks) = &action {
                    return super::overview::show(&db, &account, *address, networks.as_deref()).ok();
                }
                if let Action::Tokens(form, personal) = &action {
                    return access::tokens(&db, &db_path, &account, form.as_deref(), *personal).ok();
                }
                if let Action::Profile(form) = &action {
                    return match form {
                        Some(form) => super::profile::change_password(
                            &db, &db_path, &account, form, &sessions, profile_token.as_deref()
                        ).ok(),
                        None => Some(super::profile::page(&account, query == "password=updated")),
                    };
                }
                if let Action::Device(route, form) = &action {
                    return route
                        .serve(&db, &db_path, &method, form.as_deref(), &username)
                        .ok();
                }
                if let Action::Linux(id, root, repositories, form) = &action {
                    return super::devices::linux::serve(
                        &db,
                        &db_path,
                        *id,
                        root,
                        repositories,
                        form.as_deref(),
                        &username,
                    )
                    .ok();
                }
                if let Action::Register(form) = &action {
                    return super::devices::register(&db_path, form.as_deref()).ok();
                }
                if let Action::Bulk(route, form) = &action {
                    return route
                        .serve(&db, &db_path, form.as_deref(), &username, page)
                        .ok();
                }
                if let Action::Activity(view, catalog) = &action {
                    return view.serve(&db, &db_path, &path, &query, page, catalog).ok();
                }
                if let Action::Jobs(view) = &action {
                    return view.serve(&db, &db_path, page).ok();
                }
                if let Action::Windows(editions, form) = &action {
                    return editions.settings(&db, &db_path, form.as_deref()).ok();
                }
                if let Action::Presets(form) = &action {
                    return super::presets::serve(&db, &db_path, form.as_deref(), page).ok();
                }
                if let Action::Preset(route, form) = &action {
                    return super::presets::item(*route, &method, &db, &db_path, form.as_deref())
                        .ok();
                }
                if let Action::PullPresets = &action {
                    return super::presets::pull(&db_path).ok();
                }
                if let Action::Images(route, form, proxmox) = &action {
                    return Some(super::images::serve(
                        *route,
                        &method,
                        &db,
                        &db_path,
                        page,
                        form.as_deref(),
                        account.allows(Permission::ImagesEdit),
                        proxmox.as_deref(),
                    ));
                }
                if let Action::Setup(endpoint, form) = &action {
                    return Some(super::onboarding::serve(
                        &db,
                        &db_path,
                        endpoint,
                        form.as_deref(),
                    ));
                }
                if let Action::Acme(tls, form) = &action {
                    return Some(tls.configure_acme(form));
                }
                if let Action::Certificates(tls, form) = &action {
                    return Some(match form {
                        Some(data) => tls.import(data),
                        None => tls.page(),
                    });
                }
                if let Action::Settings(route, form) = action {
                    return route.serve(&db, &db_path, form, wants_json).ok();
                }
                if let Action::ConsolePort(visit) = action {
                    return visit.serve(&db, &username).ok();
                }
                render_devices(&db, page, &query).ok()
            })();
            (reply, authenticated)
        })
        .await;
        match reply {
            Ok((reply, authenticated)) => {
                if authenticated
                    && (!changing_password
                        || reply
                            .as_ref()
                            .is_some_and(|page| page.status() == StatusCode::SEE_OTHER))
                {
                    if let Some(window) = window {
                        self.refund_attempt(peer, window);
                    }
                }
                reply.unwrap_or_else(console_unavailable)
            }
            Err(_) => console_unavailable(),
        }
    }

    fn reserve_attempt(&self, ip: IpAddr) -> Option<Instant> {
        let mut attempts = self.attempts.lock().ok()?;
        let now = Instant::now();
        attempts.retain(|_, (started, _)| now.duration_since(*started) < Duration::from_secs(60));
        if attempts.len() >= 512 && !attempts.contains_key(&ip) {
            return None;
        }
        let (started, count) = attempts.entry(ip).or_insert((now, 0));
        if *count >= 20 {
            return None;
        }
        *count += 1;
        Some(*started)
    }

    fn refund_attempt(&self, ip: IpAddr, window: Instant) {
        let Ok(mut attempts) = self.attempts.lock() else {
            return;
        };
        if let Some((started, count)) = attempts.get_mut(&ip) {
            if *started == window {
                *count -= 1;
                if *count == 0 {
                    attempts.remove(&ip);
                }
            }
        }
    }
}

fn console_unavailable() -> Reply {
    plain(StatusCode::SERVICE_UNAVAILABLE, "Console unavailable\n")
}

fn auth_unavailable() -> Reply {
    plain(
        StatusCode::SERVICE_UNAVAILABLE,
        "Authentication is unavailable\n",
    )
}

fn sso_required() -> Reply {
    plain(
        StatusCode::UNAUTHORIZED,
        "Authentik authentication required\n",
    )
}

fn rate_limited() -> Reply {
    Response::builder()
        .status(StatusCode::TOO_MANY_REQUESTS)
        .header("Retry-After", "60")
        .body(bytes("Try again later\n"))
        .expect("valid rate limit response")
}

fn credentials(value: &str) -> Option<(String, Vec<u8>)> {
    let (scheme, payload) = value.split_once(' ')?;
    if !scheme.eq_ignore_ascii_case("basic") || payload.len() > 1024 {
        return None;
    }
    let decoded = STANDARD.decode(payload).ok()?;
    let colon = decoded.iter().position(|byte| *byte == b':')?;
    let (username, password) = decoded.split_at(colon);
    let username = std::str::from_utf8(username).ok()?;
    if username.is_empty() || username.len() > 256 {
        return None;
    }
    Some((username.to_owned(), password[1..].to_vec()))
}

pub(super) fn unauthorized() -> Reply {
    Response::builder()
        .status(StatusCode::UNAUTHORIZED)
        .header(
            WWW_AUTHENTICATE,
            r#"Basic realm="PXE Tools", charset="UTF-8""#,
        )
        .header("Cache-Control", "no-store")
        .body(bytes("Authentication required\n"))
        .expect("valid challenge response")
}

fn render_devices(db: &Connection, page: u32, query: &str) -> rusqlite::Result<Reply> {
    let mut search = String::new();
    let mut state = "";
    for (key, value) in form_urlencoded::parse(query.as_bytes()) {
        if key == "q" {
            search = value.trim().chars().take(120).collect();
        } else if key == "status" {
            state = DEVICE_STATES
                .iter()
                .find(|(key, _)| *key == value)
                .map_or("", |(key, _)| *key);
        }
    }
    let pattern = format!(
        "%{}%",
        search
            .replace('!', "!!")
            .replace('%', "!%")
            .replace('_', "!_")
    );
    let mut rows = db.prepare(
        "SELECT COALESCE(hostname,''), mac, status, id FROM machines
         WHERE (?1='' OR status=?1) AND (?2='%%' OR hostname LIKE ?2 ESCAPE '!' OR mac LIKE ?2 ESCAPE '!')
         ORDER BY id LIMIT ?3 OFFSET ?4",
    )?;
    let mut rows = rows.query(params![
        state,
        pattern,
        PAGE_SIZE as u64 + 1,
        u64::from(page - 1) * PAGE_SIZE as u64
    ])?;
    let mut html = String::from(
        r#"<nav class="device-actions inventory-actions" aria-label="Actions de l’inventaire">
<a class="button" href="/admin/inventaire/new">Ajouter un appareil</a>
<a class="button button--quiet" href="/admin/inventaire/bulk">Déployer par lots</a>
<a class="button button--quiet" href="/admin/inventaire/bulk-delete">Supprimer par lots</a></nav>"#,
    );
    if crate::setting(db, "wizard_completed")? != "1" {
        html.push_str(r#"<p><a href="/init/network">Poursuivre la configuration initiale</a></p>"#);
    }
    html.push_str(r#"<form class="panel filter-form inventory-filters" method="get" action="/admin/inventaire" aria-label="Filtrer l’inventaire">
<div class="field"><label for="inventory-search">Recherche</label><input id="inventory-search" name="q" type="search" maxlength="120" placeholder="Nom d’hôte ou adresse MAC" value=""#);
    escape(&mut html, &search);
    html.push_str(r#""></div><div class="field"><label for="inventory-filter">État</label><select id="inventory-filter" name="status"><option value="">Tous les états</option>"#);
    for &(key, label) in DEVICE_STATES {
        let selected = if key == state { " selected" } else { "" };
        html.push_str(&format!(
            r#"<option value="{key}"{selected}>{label}</option>"#
        ));
    }
    html.push_str(r#"</select></div><div class="form-actions"><button>Filtrer</button><a class="button button--quiet" href="/admin/inventaire">Effacer les filtres</a></div></form>"#);
    html.push_str(r#"<div class="table-region device-inventory" role="region" aria-label="Inventaire des appareils" tabindex="0"><table role="table"><thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">Nom d’hôte</th><th scope="col" role="columnheader">Adresse MAC</th><th scope="col" role="columnheader">État</th></tr></thead><tbody role="rowgroup">"#);
    let mut count = 0;
    let mut has_next = false;
    while let Some(row) = rows.next()? {
        if count == PAGE_SIZE {
            has_next = true;
            break;
        }
        let id = row.get::<_, i64>(3)?;
        html.push_str(&format!(
            r#"<tr class="inventory-row" role="row"><td class="inventory-host" role="cell"><a href="/admin/inventaire/{id}">"#
        ));
        let hostname = row.get::<_, String>(0)?;
        escape(
            &mut html,
            if hostname.is_empty() {
                "Ouvrir"
            } else {
                &hostname
            },
        );
        html.push_str("</a></td>");
        html.push_str(r#"<td class="table-mac" role="cell">"#);
        escape(&mut html, &row.get::<_, String>(1)?);
        html.push_str(
            r#"</td><td class="inventory-state" role="cell"><span class="device-state">"#,
        );
        let status: String = row.get(2)?;
        escape(
            &mut html,
            DEVICE_STATES
                .iter()
                .find(|(key, _)| *key == status)
                .map_or(status.as_str(), |(_, label)| *label),
        );
        html.push_str("</span></td>");
        html.push_str("</tr>");
        count += 1;
    }
    if count == 0 {
        html.push_str(if search.is_empty() && state.is_empty() {
            r#"<tr role="row"><td colspan="3" class="table-empty" role="cell"><strong>Aucun appareil dans l’inventaire</strong>Les appareils détectés par PXE apparaîtront ici.</td></tr>"#
        } else {
            r#"<tr role="row"><td colspan="3" class="table-empty" role="cell">Aucun appareil ne correspond aux filtres.</td></tr>"#
        });
    }
    html.push_str("</tbody></table></div>");
    if page > 1 || has_next {
        html.push_str(r#"<nav aria-label="Pagination">"#);
        let link = |number: u32| {
            form_urlencoded::Serializer::new(String::new())
                .append_pair("page", &number.to_string())
                .append_pair("q", &search)
                .append_pair("status", state)
                .finish()
        };
        if page > 1 {
            html.push_str(r#"<a rel="prev" href="/admin/inventaire?"#);
            escape(&mut html, &link(page - 1));
            html.push_str(r#"">Précédente</a> "#);
        }
        if has_next && page < u32::MAX {
            html.push_str(r#"<a rel="next" href="/admin/inventaire?"#);
            escape(&mut html, &link(page + 1));
            html.push_str(r#"">Suivante</a>"#);
        }
        html.push_str("</nav>");
    }
    Ok(ui::page(ConsoleSection::Devices, "Inventaire", &html))
}

pub(super) fn escape(output: &mut String, value: &str) {
    for char in value.chars() {
        match char {
            '&' => output.push_str("&amp;"),
            '<' => output.push_str("&lt;"),
            '>' => output.push_str("&gt;"),
            '"' => output.push_str("&quot;"),
            '\'' => output.push_str("&#39;"),
            _ => output.push(char),
        }
    }
}
