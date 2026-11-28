// SPDX-License-Identifier: LGPL-3.0-only

use super::{
    access::{Account, Permission},
    admin::escape,
    bytes, Reply,
};
use base64::{
    engine::general_purpose::{STANDARD, URL_SAFE_NO_PAD},
    Engine,
};
use hyper::{header, Response, StatusCode};
use sha2::{Digest, Sha256};
use std::{cell::RefCell, fmt::Write, sync::LazyLock};

const MARK: &str = "/console-assets/mark.svg?v=2";
const STYLE: &[u8] = include_bytes!("ui/style.css");
static STYLE_VERSION: LazyLock<String> =
    LazyLock::new(|| URL_SAFE_NO_PAD.encode(&Sha256::digest(STYLE)[..9]));
const NAVIGATION_SCRIPT: &str = include_str!("ui/navigation.js");
const SETUP_COMMAND_SCRIPT: &str = include_str!("ui/setup-command.js");
const WIZARD_IMAGES_SCRIPT: &str = include_str!("ui/wizard-images.js");
const POLICY: &str = "default-src 'none'; style-src 'self'; img-src 'self'; font-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";
static CONSOLE_POLICY: LazyLock<String> =
    LazyLock::new(|| format!("{}; connect-src 'self'", script_policy(NAVIGATION_SCRIPT)));
static SETUP_POLICY: LazyLock<String> = LazyLock::new(|| script_policy(SETUP_COMMAND_SCRIPT));
static WIZARD_IMAGES_POLICY: LazyLock<String> = LazyLock::new(|| {
    format!(
        "{}; connect-src 'self'",
        script_policy(WIZARD_IMAGES_SCRIPT)
    )
});

fn script_policy(script: &str) -> String {
    let digest = STANDARD.encode(Sha256::digest(script.as_bytes()));
    format!("{POLICY}; script-src 'sha256-{digest}'")
}

const MENU: &str = "M4 6h16M4 12h16M4 18h16";
const CLOSE: &str = "m6 6 12 12M18 6 6 18";
const PERSON: &str = "M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M14 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z";
const KEY: &str = "M21 7a5 5 0 0 1-6.4 4.8L7 19H3v-4l7.2-7.6A5 5 0 1 1 21 7zm-5 0h.01";
const LOGOUT: &str = "M10 4H4v16h6m-1-8h11m-4-4 4 4-4 4";
const CHEVRON: &str = "m6 9 6 6 6-6";
const BELL: &str = "M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-8 12a2 2 0 0 0 4 0";

fn brand() -> String {
    format!(
        r#"<span class="brand"><img src="{MARK}" width="32" height="32" alt="">PXE Tools</span>"#
    )
}

#[derive(Clone, Copy, PartialEq, Eq)]
pub(super) enum WizardStep {
    Code,
    Account,
    Network,
    Images,
    Confirmation,
}

pub(super) fn wizard(
    step: Option<WizardStep>,
    title: &str,
    content: &str,
    status: StatusCode,
) -> Reply {
    wizard_document(step, title, content, status, None)
}

pub(super) fn wizard_images(content: &str) -> Reply {
    wizard_document(
        Some(WizardStep::Images),
        "Images de démarrage",
        content,
        StatusCode::OK,
        Some((WIZARD_IMAGES_SCRIPT, WIZARD_IMAGES_POLICY.as_str())),
    )
}

#[derive(Clone, Copy)]
pub(super) enum SetupCodeHelp {
    Host,
    Container,
}

pub(super) fn wizard_with_setup_code(help: SetupCodeHelp, form: &str, status: StatusCode) -> Reply {
    let (command, terminal) = match help {
        SetupCodeHelp::Host => ("sudo pxe-tools setup", "sur le serveur PXE Tools"),
        SetupCodeHelp::Container => (
            "cat /run/pxe-tools-rust-guided/setup-code",
            "dans le conteneur PXE Tools",
        ),
    };
    let content = format!(
        r#"{form}<details class="setup-help"><summary>Où trouver le code ?</summary>
<p id="setup-command-help">Exécutez cette commande dans un terminal {terminal}, puis saisissez le code affiché.</p>
<div class="setup-command-row">
<input id="setup-command" type="text" value="{command}" readonly spellcheck="false" aria-label="Commande pour récupérer le code" aria-describedby="setup-command-help">
<button id="copy-setup-command" class="button--quiet" type="button" aria-label="Copier la commande" hidden>Copier</button>
</div>
<p id="setup-command-feedback" class="setup-command-feedback" role="status"></p>
</details>"#
    );
    wizard_document(
        Some(WizardStep::Code),
        "Code de configuration",
        &content,
        status,
        Some((SETUP_COMMAND_SCRIPT, SETUP_POLICY.as_str())),
    )
}

pub(super) fn wizard_welcome() -> Reply {
    wizard_document(
        None,
        "Bienvenue dans PXE Tools",
        r#"<p class="wizard-intro">Créez votre compte d’administration. Vous pourrez ensuite choisir le mode réseau et les images de démarrage de votre serveur.</p>
<div class="form-actions"><a class="button" id="setup-continue" href="/init/code">Commencer</a></div>"#,
        StatusCode::OK,
        Some((SETUP_COMMAND_SCRIPT, SETUP_POLICY.as_str())),
    )
}

fn wizard_document(
    step: Option<WizardStep>,
    title: &str,
    content: &str,
    status: StatusCode,
    script: Option<(&str, &str)>,
) -> Reply {
    let mut layout = format!(
        r#"<header class="wizard-header">{}</header><main class="wizard-main">"#,
        brand()
    );
    if let Some(step) = step {
        layout.push_str(r#"<ol class="wizard-steps" aria-label="Étapes de configuration">"#);
        for (phase, label) in [
            (WizardStep::Code, "Code"),
            (WizardStep::Account, "Compte"),
            (WizardStep::Network, "Réseau"),
            (WizardStep::Images, "Images"),
            (WizardStep::Confirmation, "Validation"),
        ] {
            let current = if step == phase {
                r#" aria-current="step""#
            } else {
                ""
            };
            write!(layout, "<li{current}>{label}</li>").expect("write to String");
        }
        layout.push_str("</ol>");
    }
    layout.push_str(r#"<section class="panel wizard-panel" aria-labelledby="wizard-title"><h1 id="wizard-title">"#);
    escape(&mut layout, title);
    layout.push_str("</h1>");
    layout.push_str(content);
    layout.push_str("</section></main>");
    document("wizard", title, &layout, status, script)
}

pub(super) fn wizard_error(
    step: WizardStep,
    status: StatusCode,
    message: &str,
    back: &'static str,
) -> Reply {
    let mut html = String::from(r#"<p class="wizard-error">"#);
    escape(&mut html, message.trim());
    write!(
        html,
        r#"</p><div class="form-actions"><a class="button button--quiet" href="{back}">Revenir au formulaire</a></div>"#
    )
    .expect("write to String");
    wizard(Some(step), "Vérifiez la configuration", &html, status)
}

pub(super) fn icon(path: &str) -> String {
    format!(
        r#"<svg class="nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{path}"/></svg>"#
    )
}

pub(super) fn status_badge(output: &mut String, status: &str, label: &str) {
    let tone = match status {
        "success" | "downloaded" => "success",
        "running" | "downloading" => "active",
        "failed" | "error" => "error",
        _ => "neutral",
    };
    write!(
        output,
        r#"<span class="status-badge status-badge--{tone}">"#
    )
    .expect("write to String");
    escape(output, label);
    output.push_str("</span>");
}

#[derive(Clone, Copy, PartialEq, Eq)]
#[repr(u8)]
pub(super) enum ConsoleSection {
    Devices,
    Activity,
    Jobs,
    Presets,
    Images,
    Settings,
    Certificates,
    Accounts,
    Tokens,
    Profile,
    Overview,
    Updates,
}

impl ConsoleSection {
    fn visible_to(self, account: &Account) -> bool {
        use Permission::*;
        match self {
            Self::Devices => account.allows(DevicesRead),
            Self::Activity => account.allows(ActivityRead),
            Self::Jobs => account.allows(JobsRead),
            Self::Presets => account.allows(PresetsRead),
            Self::Images => account.allows(ImagesRead),
            Self::Settings | Self::Overview => account.allows(SettingsRead),
            Self::Certificates | Self::Accounts => account.admin,
            Self::Updates => account.admin && crate::apt_updates::enabled(),
            Self::Tokens | Self::Profile => true,
        }
    }

    fn bit(self) -> u16 {
        1 << self as u8
    }
}

type NavLink = (&'static str, &'static str, ConsoleSection, &'static str);

const NAVIGATION: [(&str, &[NavLink]); 3] = [
    (
        "Opérations",
        &[
            (
                "/admin/inventaire",
                "Inventaire",
                ConsoleSection::Devices,
                "M3 4h18v12H3zM8 20h8m-4-4v4",
            ),
            (
                super::overview::ROUTE,
                "Vue d’ensemble",
                ConsoleSection::Overview,
                "M3 3h7v7H3zm11 0h7v7h-7zM3 14h7v7H3zm11 0h7v7h-7z",
            ),
            (
                "/activity",
                "Activité",
                ConsoleSection::Activity,
                "M3 12h4l3-6 4 12 3-6h4",
            ),
            (
                "/jobs",
                "Tâches",
                ConsoleSection::Jobs,
                "M9 5h11M9 12h11M9 19h11m-17-14h1m-1 7h1m-1 7h1",
            ),
        ],
    ),
    (
        "Configuration",
        &[
            (
                "/presets",
                "Modèles d’installation",
                ConsoleSection::Presets,
                "M5 3h10l4 4v14H5zM14 3v5h5M8 12h8m-8 4h8",
            ),
            (
                "/settings/images",
                "Images PXE",
                ConsoleSection::Images,
                "M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0zm-7 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0zM6.6 9a6 6 0 0 1 2.4-2.4m8.4 8.4a6 6 0 0 1-2.4 2.4",
            ),
            (
                "/settings",
                "Paramètres PXE",
                ConsoleSection::Settings,
                "M4 6h5m4 0h7M4 12h11m4 0h1M4 18h2m4 0h10M9 4v4m6 2v4m-9 2v4",
            ),
            (
                "/admin/certificates",
                "Certificats TLS",
                ConsoleSection::Certificates,
                "m12 3 8 3v5c0 5-3 8-8 10-5-2-8-5-8-10V6zm-3 9 2 2 4-4",
            ),
            (
                super::updates::ROUTE,
                "Mises à jour",
                ConsoleSection::Updates,
                "M12 3v12m-5-5 5 5 5-5M5 16v4h14v-4",
            ),
        ],
    ),
    (
        "Accès",
        &[(
            super::access::USERS,
            "Comptes et droits",
            ConsoleSection::Accounts,
            "M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-4M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zm5-4a4 4 0 0 1 0 8",
        )],
    ),
];

struct Navigation {
    mask: u16,
    account: Account,
}

thread_local! {
    // Rendering runs synchronously on the blocking worker. This state only
    // controls presentation and never authorizes an operation.
    static NAVIGATION_CONTEXT: RefCell<Option<Navigation>> = const { RefCell::new(None) };
}

pub(super) struct NavigationGuard(Option<Navigation>);

impl Drop for NavigationGuard {
    fn drop(&mut self) {
        NAVIGATION_CONTEXT.replace(self.0.take());
    }
}

pub(super) fn navigation_for(account: &Account) -> NavigationGuard {
    let mask = NAVIGATION
        .iter()
        .flat_map(|(_, links)| *links)
        .filter(|(_, _, section, _)| section.visible_to(account))
        .fold(0, |mask, (_, _, section, _)| mask | section.bit());
    NavigationGuard(NAVIGATION_CONTEXT.replace(Some(Navigation {
        mask,
        account: account.clone(),
    })))
}

pub(super) fn home(account: &Account) -> &'static str {
    NAVIGATION
        .iter()
        .flat_map(|(_, links)| *links)
        .find(|(_, _, section, _)| section.visible_to(account))
        .map_or(super::profile::ROUTE, |(path, ..)| *path)
}

fn profile_menu(account: &Account, section: ConsoleSection) -> String {
    let mut html = format!(
        r#"<details class="profile-menu"><summary class="button button--quiet">{}<span class="profile-name">"#,
        icon(PERSON),
    );
    escape(&mut html, &account.username);
    write!(
        html,
        r#"</span>{}</summary><div class="profile-options">"#,
        icon(CHEVRON)
    )
    .expect("write to String");
    html.push_str(r#"<p class="profile-account">"#);
    escape(&mut html, &account.username);
    html.push_str("</p>");
    let mut link = |path, label, item, glyph| {
        let current = if section == item {
            r#" aria-current="page""#
        } else {
            ""
        };
        write!(
            html,
            r#"<a class="profile-option" href="{path}"{current}>{}<span>{label}</span></a>"#,
            icon(glyph)
        )
        .expect("write to String");
    };
    link(
        super::profile::ROUTE,
        "Mon profil",
        ConsoleSection::Profile,
        PERSON,
    );
    if account.id.is_some() {
        link(
            super::access::PERSONAL_TOKENS,
            "Mes jetons d’API",
            ConsoleSection::Tokens,
            KEY,
        );
    }
    if !account.is_external() {
        write!(html, r#"<form method="post" action="/logout"><button type="submit" class="profile-option">{}<span>Se déconnecter</span></button></form>"#, icon(LOGOUT))
            .expect("write to String");
    }
    html.push_str("</div></details>");
    html
}

fn notification_menu(account: &Account) -> String {
    let identity = format!("{:?}:{}:{}", account.id, account.is_external(), account.username);
    let key = URL_SAFE_NO_PAD.encode(&Sha256::digest(identity)[..12]);
    let check = if super::notifications::updates_for(account) {
        " data-check-updates"
    } else {
        ""
    };
    let route = super::notifications::ROUTE;
    format!(
        r#"<details class="notification-menu" data-notifications="{route}" data-account="{key}"{check}>
<summary class="button button--quiet" aria-label="Notifications">{}<span data-notification-count aria-hidden="true" hidden></span></summary>
<section class="notification-panel" aria-labelledby="notifications-heading">
<header class="notification-heading"><h2 id="notifications-heading">Notifications</h2>
<button type="button" class="button--quiet" data-notification-read disabled>Tout marquer comme lu</button></header>
<p class="notification-status" data-notification-status role="status">Chargement des notifications…</p>
<ul class="notification-list" data-notification-list></ul>
</section></details><span class="notification-announcement" data-notification-announcement role="status"></span>"#,
        icon(BELL),
    )
}

pub(super) fn page(section: ConsoleSection, title: &str, content: &str) -> Reply {
    let brand = brand();
    let (mask, actions) = NAVIGATION_CONTEXT.with_borrow(|context| {
        context.as_ref().map_or_else(
            || (u16::MAX, String::new()),
            |nav| {
                let notices = super::notifications::available_to(&nav.account)
                    .then(|| notification_menu(&nav.account))
                    .unwrap_or_default();
                let profile = profile_menu(&nav.account, section);
                (
                    nav.mask,
                    format!(r#"<div class="masthead-actions">{notices}{profile}</div>"#),
                )
            },
        )
    });
    let show = |section: ConsoleSection| section.bit() & mask != 0;
    let home = NAVIGATION
        .iter()
        .flat_map(|(_, links)| *links)
        .find(|(_, _, section, _)| show(*section))
        .map_or(super::profile::ROUTE, |(path, ..)| *path);
    let mut layout = String::from(
        r##"<a class="skip-link" href="#main">Aller au contenu</a><header class="masthead">"##,
    );
    write!(
        layout,
        r#"<a class="brand-link" href="{home}">{brand}</a><span class="masthead-label">Console d’administration</span>
<button type="button" class="button button--quiet mobile-toggle" aria-label="Ouvrir le menu de navigation" aria-haspopup="dialog" aria-controls="mobile-navigation" aria-expanded="false">{}<span>Menu</span></button>"#,
        icon(MENU),
    )
    .expect("write to String");
    layout.push_str(&actions);
    let mut navigation = String::new();
    for (group, links) in NAVIGATION {
        if !links.iter().any(|(_, _, item, _)| show(*item)) {
            continue;
        }
        write!(navigation, r#"<div class="rail-group"><p>{group}</p>"#).expect("write to String");
        for &(path, name, item, glyph) in links {
            if !show(item) {
                continue;
            }
            let current = if item == section {
                r#" aria-current="page""#
            } else {
                ""
            };
            write!(
                navigation,
                r#"<a href="{path}"{current}>{}<span>{name}</span></a>"#,
                icon(glyph)
            )
            .expect("write to String");
        }
        navigation.push_str("</div>");
    }
    write!(
        layout,
        r#"</header><dialog id="mobile-navigation" class="mobile-drawer" aria-label="Navigation PXE Tools">
<div class="drawer-body"><div class="drawer-header">{brand}<form method="dialog"><button type="submit" class="button button--quiet drawer-close" aria-label="Fermer le menu" autofocus>{}</button></form></div>
<nav class="rail" aria-label="Navigation principale">{navigation}</nav>"#,
        icon(CLOSE)
    )
    .expect("write to String");
    write!(
        layout,
        r#"</div></dialog><div class="workspace"><nav class="rail" aria-label="Navigation principale">{navigation}</nav><main id="main" class="console-main" tabindex="-1"><h1>"#
    )
    .expect("write to String");
    escape(&mut layout, title);
    layout.push_str("</h1>");
    layout.push_str(content);
    layout.push_str("</main></div>");
    document(
        "console",
        title,
        &layout,
        StatusCode::OK,
        Some((NAVIGATION_SCRIPT, CONSOLE_POLICY.as_str())),
    )
}

pub(super) fn guest(title: &str, content: &str, status: StatusCode) -> Reply {
    let mut layout = format!(
        r#"<div class="auth-layout">
<header class="auth-brand">{}</header>
<div class="auth-image"><p>Inventaire et<br>déploiement réseau</p></div>
<main class="auth-main"><div class="auth-content"><h1>"#,
        brand()
    );
    escape(&mut layout, title);
    layout.push_str("</h1>");
    layout.push_str(content);
    layout.push_str("</div></main></div>");
    document("guest", title, &layout, status, None)
}

fn document(
    class: &str,
    title: &str,
    layout: &str,
    status: StatusCode,
    script: Option<(&str, &str)>,
) -> Reply {
    let mut html = String::from(
        r#"<!doctype html><html lang="fr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><title>"#,
    );
    escape(&mut html, title);
    let style_version = STYLE_VERSION.as_str();
    write!(
        html,
        r#" · PXE Tools</title><link rel="icon" type="image/svg+xml" href="{MARK}">
<link rel="stylesheet" href="/console-assets/style.css?v={style_version}"></head><body class="{class}">{layout}"#
    )
    .expect("write to String");
    let policy = if let Some((source, policy)) = script {
        write!(html, "<script>{source}</script>").expect("write to String");
        policy
    } else {
        POLICY
    };
    html.push_str("</body></html>");
    Response::builder()
        .status(status)
        .header(header::CONTENT_TYPE, "text/html; charset=utf-8")
        .header(header::CACHE_CONTROL, "no-store")
        // A restrictive no-referrer policy also suppresses the Origin of
        // browser form submissions checked by the console.
        .header(header::REFERRER_POLICY, "same-origin")
        .header(header::X_CONTENT_TYPE_OPTIONS, "nosniff")
        .header("Content-Security-Policy", policy)
        .body(bytes(html))
        .expect("valid console page")
}

pub(super) fn asset(path: &str, head: bool) -> Option<Reply> {
    let (kind, data): (&str, &'static [u8]) = match path {
        "/console-assets/style.css" => ("text/css; charset=utf-8", STYLE),
        "/console-assets/mark.svg" => (
            "image/svg+xml",
            include_bytes!("../../../app/public/favicon.svg"),
        ),
        "/console-assets/mona.woff2" => (
            "font/woff2",
            include_bytes!("../../../app/public/fonts/mona-sans-latin-wght-normal.woff2"),
        ),
        "/console-assets/chevron.svg" => ("image/svg+xml", include_bytes!("ui/chevron.svg")),
        "/console-assets/almalinux.svg" => (
            "image/svg+xml",
            include_bytes!("../../../app/public/img/distros/almalinux.svg"),
        ),
        "/console-assets/centos.svg" => (
            "image/svg+xml",
            include_bytes!("../../../app/public/img/distros/centos.svg"),
        ),
        "/console-assets/fedora.svg" => (
            "image/svg+xml",
            include_bytes!("../../../app/public/img/distros/fedora.svg"),
        ),
        "/console-assets/proxmox.svg" => {
            ("image/svg+xml", include_bytes!("ui/proxmox-brandmark.svg"))
        }
        "/console-assets/rocky.svg" => (
            "image/svg+xml",
            include_bytes!("../../../app/public/img/distros/rocky.svg"),
        ),
        "/console-assets/debian.svg" => (
            "image/svg+xml",
            include_bytes!("../../../app/public/img/distros/debian.svg"),
        ),
        "/console-assets/ubuntu.svg" => (
            "image/svg+xml",
            include_bytes!("../../../app/public/img/distros/ubuntu.svg"),
        ),
        "/console-assets/red-hat-enterprise-linux.png" => (
            "image/png",
            include_bytes!("ui/red-hat-enterprise-linux.png"),
        ),
        "/console-assets/windows-11.png" => ("image/png", include_bytes!("ui/windows-11.png")),
        "/console-assets/server-room.webp" => ("image/webp", include_bytes!("ui/server-room.webp")),
        _ => return None,
    };
    Some(
        Response::builder()
            .header(header::CONTENT_TYPE, kind)
            .header(header::CONTENT_LENGTH, data.len())
            .header(
                header::CACHE_CONTROL,
                "public, max-age=3600, must-revalidate",
            )
            .header(header::X_CONTENT_TYPE_OPTIONS, "nosniff")
            .body(bytes(if head { &[] } else { data }))
            .expect("valid console asset"),
    )
}
