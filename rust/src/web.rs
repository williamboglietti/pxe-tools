// SPDX-License-Identifier: LGPL-3.0-only

mod access;
mod acme;
mod activity;
mod admin;
mod ansible_settings;
mod ansible_webhook;
mod api;
mod assets;
mod auth_proxy;
mod certificates;
mod console_port;
mod device_ansible;
mod device_operations;
mod device_settings;
mod devices;
mod hardware_context;
mod hostnames;
mod images;
mod jobs;
mod linux_boot;
mod notifications;
mod onboarding;
mod overview;
mod presets;
mod profile;
mod proxmox;
mod sessions;
mod settings;
mod setup;
mod ui;
mod updates;
mod winpe;

pub(crate) use console_port::{
    configured as configured_console_port, set_configured as set_console_port,
};
pub(crate) use onboarding::{finish_pxe_address, refresh_pxe_address};
pub(crate) use setup::adopt_existing_database;

use crate::{configured_database, handle_request, monitoring, setting, PxeRequest, Routes};
use http::uri::Authority;
use http_body_util::{combinators::UnsyncBoxBody, BodyExt, Full, Limited};
use hyper::{
    body::{Bytes, Incoming},
    header::{
        HeaderMap, HeaderName, CONTENT_LENGTH, CONTENT_RANGE, CONTENT_TYPE, HOST, LOCATION, ORIGIN,
        USER_AGENT,
    },
    server::conn::http1,
    service::service_fn,
    Method, Request, Response, StatusCode,
};
use hyper_util::rt::{TokioIo, TokioTimer};
use rusqlite::OpenFlags;
use std::{
    convert::Infallible,
    env,
    error::Error,
    fs::File,
    io::{self, BufReader, Cursor},
    net::{IpAddr, Ipv4Addr, SocketAddr, SocketAddrV4},
    path::{Path, PathBuf},
    str::FromStr,
    sync::{Arc, Weak},
    time::Duration,
};
use tokio::{
    io::{AsyncRead, AsyncWrite},
    net::{TcpListener, TcpStream},
    sync::Semaphore,
    task,
    time::timeout,
};

type ErrorBox = Box<dyn Error + Send + Sync>;
type Body = UnsyncBoxBody<Bytes, io::Error>;
type Reply = Response<Body>;

struct Site {
    database: PathBuf,
    assets: assets::Assets,
    catalog: Arc<monitoring::Catalog>,
    pxe_ip: Option<Ipv4Addr>,
    boot_networks: Option<Vec<crate::boot_networks::Network>>,
    addresses: Vec<Ipv4Addr>,
    local_names: Vec<String>,
    console_port: console_port::ConsolePort,
    http_port: u16,
    winpe_client: Option<Ipv4Addr>,
    rhel_repositories: linux_boot::RhelRepositories,
    public_host: Option<String>,
    requests: Semaphore,
    connections: Arc<Semaphore>,
    admin: admin::Console,
    setup: setup::Wizard,
    tls: Arc<certificates::Certificates>,
    acme_challenge: Arc<acme::ChallengeResponse>,
}

impl Site {
    fn boot_network(
        &self,
        address: Ipv4Addr,
        peer: IpAddr,
    ) -> Option<&crate::boot_networks::Network> {
        self.boot_networks.as_ref()?.iter().find(|network| {
            (address == network.address || (address.is_loopback() && peer.is_loopback()))
                && (peer.is_loopback() || network.contains(peer))
        })
    }

    fn is_pxe_address(&self, address: Ipv4Addr) -> bool {
        self.boot_networks
            .as_ref()
            .map_or(self.pxe_ip == Some(address), |networks| {
                networks.iter().any(|network| network.address == address)
            })
    }

    fn pxe_peer(
        &self,
        db: &rusqlite::Connection,
        address: Ipv4Addr,
        peer: IpAddr,
    ) -> rusqlite::Result<bool> {
        if self.boot_networks.is_some() {
            return Ok(self
                .boot_network(address, peer)
                .is_some_and(|network| network.contains(peer)));
        }
        Ok(
            matches!(peer, IpAddr::V4(peer) if crate::pxe::client_in_subnet(peer, &setting(db, "pxe_subnet")?)),
        )
    }
}

pub fn serve(config: &Path) -> Result<(), ErrorBox> {
    let runtime = tokio::runtime::Builder::new_multi_thread()
        .worker_threads(2)
        .max_blocking_threads(12)
        .enable_all()
        .build()?;
    runtime.block_on(run(config))
}

async fn run(config: &Path) -> Result<(), ErrorBox> {
    let boot_networks = crate::boot_networks::active(config, true)?;
    let pxe_ip = match &boot_networks {
        Some(networks) => networks.first().map(|network| network.address),
        None => active_pxe_address(config)?,
    };
    let mut addresses = admin_addresses()?;
    if let Some(networks) = &boot_networks {
        addresses.extend(networks.iter().map(|network| network.address));
    } else {
        addresses.extend(pxe_ip);
    }
    addresses.sort_unstable();
    addresses.dedup();
    let public_host = env::var("PXE_PUBLIC_HOST")
        .ok()
        .filter(|name| !name.is_empty())
        .map(|name| {
            let uri = Authority::from_str(&name)?;
            if uri.port().is_some() || uri.host() != name || name.contains('@') {
                return Err("PXE_PUBLIC_HOST must be a hostname or IPv4 address".into());
            }
            Ok::<String, ErrorBox>(name)
        })
        .transpose()?;
    let http_port = port("PXE_HTTP_PORT", 80)?;
    let https_port = console_port::configured(config)?;
    let default_tls = tls_config().map_err(|error| format!("TLS configuration: {error}"))?;
    let tls = Arc::new(certificates::Certificates::new(
        default_tls,
        public_host.clone(),
    )?);
    let acme_challenge = Arc::new(acme::ChallengeResponse::default());
    let assets = assets::Assets::new(
        env::var_os("HTTP_ROOT")
            .map(PathBuf::from)
            .ok_or("HTTP_ROOT is required")?,
    )?;
    let site = Arc::new(Site {
        database: config.to_owned(),
        assets,
        catalog: Arc::new(serde_json::from_str(include_str!("checkpoints.json"))?),
        pxe_ip,
        boot_networks,
        addresses,
        local_names: admin_hostnames(),
        console_port: console_port::ConsolePort::new(https_port),
        http_port,
        winpe_client: winpe::configured_client()?,
        rhel_repositories: linux_boot::RhelRepositories::from_environment(),
        public_host,
        requests: Semaphore::new(8),
        connections: Arc::new(Semaphore::new(64)),
        admin: admin::Console::new(),
        setup: setup::Wizard::new(),
        tls,
        acme_challenge,
    });
    let mut listeners = Vec::new();
    for &address in &site.addresses {
        let listener = TcpListener::bind((address, http_port))
            .await
            .map_err(|error| format!("Unable to listen on {address}:{http_port}: {error}"))?;
        listeners.push(listener);
    }
    site.console_port.start(&site)?;
    let mut servers = task::JoinSet::new();
    for listener in listeners {
        servers.spawn(accept(
            listener,
            false,
            Arc::downgrade(&site),
            site.connections.clone(),
        ));
    }
    let tls = site.tls.clone();
    let challenge = site.acme_challenge.clone();
    task::spawn(async move { acme::run(tls.acme_configuration(), &challenge, &tls).await });
    notify_ready().map_err(|error| format!("Systemd readiness notification: {error}"))?;
    for address in &site.addresses {
        println!(
            "PXE Tools Rust HTTP/HTTPS ready on {address}:{http_port} and {address}:{https_port}"
        );
    }
    tokio::select! {
        result = servers.join_next() => result.ok_or("HTTP listeners unavailable")??,
        signal = tokio::signal::ctrl_c() => signal.map_err(Into::into),
    }
}

pub(crate) fn admin_hostnames() -> Vec<String> {
    hostnames::local()
}

pub(crate) fn admin_addresses() -> Result<Vec<Ipv4Addr>, ErrorBox> {
    let mut addresses = vec![Ipv4Addr::LOCALHOST];
    if let Some(configured) = env::var_os("PXE_ADMIN_BIND").filter(|value| !value.is_empty()) {
        let address: Ipv4Addr = configured
            .to_str()
            .ok_or("PXE_ADMIN_BIND must be an IPv4 address")?
            .parse()?;
        if address != Ipv4Addr::LOCALHOST && !address.is_private() {
            return Err("PXE_ADMIN_BIND must be a private IPv4 address or 127.0.0.1".into());
        }
        if address != Ipv4Addr::LOCALHOST {
            addresses.push(address);
        }
        return Ok(addresses);
    }
    #[cfg(target_os = "linux")]
    {
        let output = std::process::Command::new("/usr/bin/ip")
            .args(["-j", "-4", "address", "show", "up", "scope", "global"])
            .output()
            .map_err(|error| format!("LAN address inspection: {error}"))?;
        if !output.status.success() {
            return Err("Unable to list the LAN addresses".into());
        }
        let links: Vec<serde_json::Value> = serde_json::from_slice(&output.stdout)?;
        addresses.extend(
            links
                .iter()
                .filter_map(|link| link["addr_info"].as_array())
                .flatten()
                .filter_map(|entry| entry["local"].as_str()?.parse::<Ipv4Addr>().ok())
                .filter(Ipv4Addr::is_private),
        );
        addresses.sort_unstable();
        addresses.dedup();
    }
    Ok(addresses)
}

pub(crate) fn active_pxe_address(config: &Path) -> Result<Option<Ipv4Addr>, ErrorBox> {
    match std::fs::symlink_metadata(config) {
        Err(error) if error.kind() == io::ErrorKind::NotFound => return Ok(None),
        Err(error) => return Err(error.into()),
        Ok(_) => {}
    }
    let path = configured_database(config).ok_or("SQLite configuration unavailable")?;
    let db = crate::connect(&path, OpenFlags::SQLITE_OPEN_READ_ONLY)?;
    let enabled = setting(&db, "pxe_enabled")?;
    if enabled == "off" {
        return Ok(None);
    }
    let address = setting(&db, "pxe_ip")?;
    if enabled.is_empty() && address.is_empty() {
        return Ok(None);
    }
    if !matches!(enabled.as_str(), "" | "on") {
        return Err("Invalid PXE activation setting".into());
    }
    if !setting(&db, onboarding::PENDING_PXE_ADDRESS)?.is_empty() {
        eprintln!("PXE démarrage suspendu : les fichiers de démarrage doivent être régénérés.");
        return Ok(None);
    }
    let Ok(ip) = address.parse::<Ipv4Addr>() else {
        eprintln!("PXE démarrage suspendu : adresse invalide.");
        return Ok(None);
    };
    if ip.is_unspecified() || ip.is_multicast() || ip.is_broadcast() {
        eprintln!("PXE démarrage suspendu : adresse invalide.");
        return Ok(None);
    }
    #[cfg(target_os = "linux")]
    if let Err(error) = validate_interface(ip, &setting(&db, "pxe_interface")?) {
        eprintln!("PXE démarrage suspendu : {error}");
        return Ok(None);
    }
    Ok(Some(ip))
}

#[cfg(target_os = "linux")]
fn validate_interface(ip: Ipv4Addr, interface: &str) -> Result<(), ErrorBox> {
    use serde_json::Value;
    use std::process::Command;

    if ip.is_loopback()
        || ip.octets()[0] >= 240
        || interface.is_empty()
        || interface.len() > 64
        || !interface
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || b"_.:-".contains(&byte))
    {
        return Err("Invalid PXE interface or address".into());
    }
    let output = Command::new("/usr/bin/ip")
        .args(["-j", "-4", "addr", "show", "up", "dev", interface])
        .output()
        .map_err(|error| format!("PXE interface inspection: {error}"))?;
    if output.status.success() {
        let links: Vec<Value> = serde_json::from_slice(&output.stdout)?;
        let address = ip.to_string();
        if let [link] = links.as_slice() {
            if link["ifname"] == interface
                && link["addr_info"].as_array().is_some_and(|addresses| {
                    addresses
                        .iter()
                        .any(|entry| entry["family"] == "inet" && entry["local"] == address)
                })
            {
                return Ok(());
            }
        }
    }
    Err("PXE address is not assigned to the selected interface".into())
}

fn port(name: &str, default: u16) -> Result<u16, ErrorBox> {
    let configured = env::var(name).map_or(Ok(default), |value| value.parse::<u16>())?;
    if configured == 0 {
        return Err(format!("Invalid TCP port in {name}").into());
    }
    Ok(configured)
}

fn certificate_pin(der: &[u8]) -> String {
    use sha2::{Digest, Sha256};
    Sha256::digest(der)
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

fn tls_config() -> Result<certificates::Endpoint, ErrorBox> {
    let directory = credential_directory()?;
    let open = |name| {
        let path = directory.join(name);
        File::open(&path)
            .map(BufReader::new)
            .map_err(|error| format!("cannot open {}: {error}", path.display()))
    };
    let certs = rustls_pemfile::certs(&mut open("cert.pem")?).collect::<Result<Vec<_>, _>>()?;
    let private_key =
        rustls_pemfile::private_key(&mut open("key.pem")?)?.ok_or("TLS private key missing")?;
    certificates::Endpoint::new(certs, private_key)
}

fn credential_directory() -> Result<PathBuf, ErrorBox> {
    env::var_os("CREDENTIALS_DIRECTORY")
        .or_else(|| env::var_os("TLS_DIR"))
        .map(PathBuf::from)
        .ok_or_else(|| "TLS_DIR is required".into())
}

#[cfg(target_os = "linux")]
fn notify_ready() -> io::Result<()> {
    use std::os::{
        linux::net::SocketAddrExt,
        unix::{
            ffi::OsStrExt,
            net::{SocketAddr, UnixDatagram},
        },
    };
    if let Some(socket) = env::var_os("NOTIFY_SOCKET") {
        let address = if let Some(name) = socket.as_bytes().strip_prefix(b"@") {
            SocketAddr::from_abstract_name(name)?
        } else {
            SocketAddr::from_pathname(socket)?
        };
        UnixDatagram::unbound()?.send_to_addr(b"READY=1", &address)?;
    }
    Ok(())
}

#[cfg(not(target_os = "linux"))]
fn notify_ready() -> io::Result<()> {
    Ok(())
}

async fn accept(
    listener: TcpListener,
    tls: bool,
    site: Weak<Site>,
    connections: Arc<Semaphore>,
) -> Result<(), ErrorBox> {
    let SocketAddr::V4(local) = listener.local_addr()? else {
        return Err("Expected an IPv4 listening socket".into());
    };
    loop {
        let (stream, peer) = listener.accept().await?;
        let Ok(permit) = connections.clone().try_acquire_owned() else {
            continue;
        };
        let Some(site) = site.upgrade() else {
            return Ok(());
        };
        task::spawn(async move {
            let _permit = permit;
            if tls {
                let tls = site.tls.acceptor();
                if let Ok(Ok(stream)) = timeout(Duration::from_secs(10), tls.accept(stream)).await {
                    connection(stream, peer, local, true, site).await;
                }
            } else {
                connection::<TcpStream>(stream, peer, local, false, site).await;
            }
        });
    }
}

async fn connection<S>(
    stream: S,
    peer: SocketAddr,
    local: SocketAddrV4,
    secure: bool,
    site: Arc<Site>,
) where
    S: AsyncRead + AsyncWrite + Send + Unpin + 'static,
{
    let service = service_fn(|request: Request<Incoming>| {
        let site = site.clone();
        async move {
            let grub = !secure
                && single(request.headers(), USER_AGENT.as_str())
                    .is_some_and(|agent| agent.starts_with("GRUB "));
            let asset = if grub && request.uri().path().starts_with("/netboot/") {
                match request.uri().path().rsplit('/').next() {
                    Some("linux") => Some("linux"),
                    Some("initrd.gz") => Some("initrd.gz"),
                    Some("firmware.cpio.gz") => Some("firmware.cpio.gz"),
                    _ => None,
                }
            } else {
                None
            };
            let path = request.uri().path();
            let winpe_path = (request.method() == Method::GET
                && winpe::allowed(site.winpe_client, peer.ip())
                && winpe::logs_request(path))
            .then(|| path.to_owned());
            let reply = dispatch(request, peer.ip(), local, secure, &site).await;
            if let Some(asset) = asset {
                let length = single(reply.headers(), CONTENT_LENGTH.as_str()).unwrap_or("-");
                println!(
                    "PXE GRUB asset={asset} status={} bytes={length}",
                    reply.status().as_u16()
                );
            }
            if let Some(path) = winpe_path {
                let length = single(reply.headers(), CONTENT_LENGTH.as_str()).unwrap_or("-");
                let range = single(reply.headers(), CONTENT_RANGE.as_str()).unwrap_or("-");
                println!(
                    "PXE WinPE asset={path} status={} bytes={length} range={range}",
                    reply.status().as_u16()
                );
            }
            Ok::<_, Infallible>(reply)
        }
    });
    let connection = http1::Builder::new()
        // GRUB 2.12 matches Content-Length case-sensitively in its HTTP
        // client. Secure Boot verification requires a known kernel size.
        .title_case_headers(true)
        .timer(TokioTimer::new())
        .header_read_timeout(Duration::from_secs(10))
        .max_buf_size(32 * 1024)
        .serve_connection(TokioIo::new(stream), service);
    // A stalled receiver can keep at most one connection slot for this period.
    let _ = timeout(Duration::from_secs(300), connection).await;
}

async fn windows_editions(site: &Site) -> Arc<winpe::Editions> {
    let root = site.assets.winpe_root();
    Arc::new(
        task::spawn_blocking(move || {
            winpe::Editions::load(&root).unwrap_or_else(|error| {
                eprintln!("Unable to load the Windows editions manifest: {error}");
                winpe::Editions::Invalid
            })
        })
        .await
        .unwrap_or(winpe::Editions::Invalid),
    )
}

async fn dispatch(
    request: Request<Incoming>,
    peer: IpAddr,
    listener: SocketAddrV4,
    secure: bool,
    site: &Arc<Site>,
) -> Reply {
    let (parts, body) = request.into_parts();
    let path = parts.uri.path();
    let readable = matches!(parts.method, Method::GET | Method::HEAD);
    let local = *listener.ip();
    let (https_port, testing_port) = site.console_port.access(listener.port());
    if secure && listener.port() != https_port {
        if !testing_port {
            return plain(StatusCode::GONE, "Ce port de console a été fermé.\n");
        }
        if !matches!(path, console_port::ROUTE | "/login") && !path.starts_with("/console-assets/")
        {
            return setup::redirect(StatusCode::SEE_OTHER, console_port::ROUTE);
        }
    }
    if path == console_port::ROUTE {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        let host = admin_authority(&parts.headers, HOST.as_str(), site)
            .filter(|authority| authority.port_u16().unwrap_or(443) == listener.port())
            .map(|authority| authority.host().to_owned());
        let form = if readable {
            None
        } else if parts.method == Method::POST {
            if host.is_none()
                || !admin_origin_on(&parts.headers, HOST.as_str(), site, Some(listener.port()))
            {
                return plain(StatusCode::FORBIDDEN, "Invalid origin\n");
            }
            match read_form(&parts.headers, body, 256).await {
                Ok(form) => Some(form),
                Err(reply) => return reply,
            }
        } else {
            return plain(StatusCode::METHOD_NOT_ALLOWED, "Method not allowed\n");
        };
        return site
            .admin
            .serve(
                &parts,
                peer,
                &site.database,
                admin::Action::ConsolePort(console_port::Visit {
                    site: site.clone(),
                    port: listener.port(),
                    host,
                    form,
                }),
            )
            .await;
    }
    let canonical_path;
    let path = if let Some(suffix) = ["/machines", "/admin/devices"]
        .into_iter()
        .filter_map(|prefix| path.strip_prefix(prefix))
        .find(|suffix| suffix.is_empty() || suffix.starts_with('/'))
    {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        canonical_path = format!("/admin/inventaire{suffix}");
        if readable {
            let target = match parts.uri.query() {
                Some(query) => format!("{canonical_path}?{query}"),
                None => canonical_path,
            };
            return setup::redirect(StatusCode::FOUND, target);
        }
        &canonical_path
    } else {
        path
    };
    if secure && readable && path.starts_with("/console-assets/") {
        return ui::asset(path, parts.method == Method::HEAD)
            .unwrap_or_else(|| plain(StatusCode::NOT_FOUND, "Not found\n"));
    }
    if !secure && parts.method == Method::GET && path.starts_with(acme::PREFIX) {
        return site
            .acme_challenge
            .serve(path, single(&parts.headers, HOST.as_str()));
    }
    if path == "/healthz" && readable {
        return plain(StatusCode::OK, "ok\n");
    }
    if path.starts_with(api::PREFIX) {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        let Ok(_permit) = site.requests.try_acquire() else {
            return plain(StatusCode::SERVICE_UNAVAILABLE, "Busy\n");
        };
        let mut reply = api::serve(parts, body, &site.database)
            .await
            .unwrap_or_else(|_| {
                api::error(StatusCode::SERVICE_UNAVAILABLE, "api_unavailable")
                    .expect("valid error response")
            });
        reply.headers_mut().insert(
            hyper::header::X_CONTENT_TYPE_OPTIONS,
            hyper::header::HeaderValue::from_static("nosniff"),
        );
        return reply;
    }
    if path == "/" && readable {
        let location = if secure {
            "/admin/".to_owned()
        } else {
            let requested_host = single(&parts.headers, HOST.as_str())
                .filter(|value| !value.contains('@'))
                .and_then(|value| value.parse::<Authority>().ok());
            let issued_host = site.tls.issued_host();
            let public_host = issued_host.as_deref().or(site.public_host.as_deref());
            let host = site
                .is_pxe_address(local)
                .then_some(())
                .and(public_host)
                .or_else(|| {
                    requested_host.as_ref().map(Authority::host).filter(|name| {
                        (local.is_loopback() && name.eq_ignore_ascii_case("localhost"))
                            || public_host.is_some_and(|host| host.eq_ignore_ascii_case(name))
                            || (!name.trim_end_matches('.').eq_ignore_ascii_case("localhost")
                                && known_dns_name(site, name))
                    })
                })
                .map_or_else(|| local.to_string(), str::to_owned);
            let port = if https_port == 443 {
                String::new()
            } else {
                format!(":{https_port}")
            };
            format!("https://{host}{port}/admin/")
        };
        return Response::builder()
            .status(StatusCode::FOUND)
            .header(LOCATION, location)
            .body(bytes(""))
            .expect("valid redirect");
    }
    if path.starts_with(proxmox::PREFIX) {
        return proxmox::serve(site, path, &parts.method, body, peer, local, secure).await;
    }
    let setup_allowed = site.pxe_ip.is_none()
        && (peer.is_loopback()
            || (local.is_private() && matches!(peer, IpAddr::V4(address) if address.is_private())));
    if secure && readable && path == "/admin/" && setup_allowed {
        if let Some(reply) = site.setup.landing(&site.database).await {
            return reply;
        }
    }
    if matches!(
        path,
        "/init" | "/init/welcome" | "/init/code" | "/init/db" | "/init/admin"
    ) {
        if !secure || !setup_allowed {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        if readable {
            return site.setup.page(path, &site.database, &parts.headers).await;
        }
        if parts.method == Method::POST && matches!(path, "/init/code" | "/init/db" | "/init/admin")
        {
            if !admin_origin(&parts.headers, peer, site) {
                return plain(StatusCode::FORBIDDEN, "Invalid origin\n");
            }
            let form = match read_form(&parts.headers, body, 2048).await {
                Ok(form) => form,
                Err(reply) => return reply,
            };
            return site
                .setup
                .submit(path, &site.database, &parts.headers, &form)
                .await;
        }
        return plain(StatusCode::METHOD_NOT_ALLOWED, "Method not allowed\n");
    }
    if matches!(path, "/login" | "/logout") {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        if path == "/login" && readable {
            return site.admin.login(&parts, peer, &site.database, None).await;
        }
        if parts.method != Method::POST {
            return plain(StatusCode::METHOD_NOT_ALLOWED, "Method not allowed\n");
        }
        let valid_origin = if listener.port() == https_port {
            admin_origin(&parts.headers, peer, site)
        } else {
            admin_origin_on(&parts.headers, HOST.as_str(), site, Some(listener.port()))
        };
        if !valid_origin {
            return plain(StatusCode::FORBIDDEN, "Invalid origin\n");
        }
        if path == "/logout" {
            return site.admin.logout(&parts.headers).await;
        }
        let form = match read_form(&parts.headers, body, 2048).await {
            Ok(form) => form,
            Err(reply) => return reply,
        };
        return site
            .admin
            .login(&parts, peer, &site.database, Some(form))
            .await;
    }
    if matches!(
        path,
        access::USERS | access::TOKENS | access::PERSONAL_TOKENS | profile::ROUTE
    ) {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        let form = if readable {
            None
        } else if parts.method == Method::POST {
            if !admin_origin(&parts.headers, peer, site) {
                return plain(StatusCode::FORBIDDEN, "Invalid origin\n");
            }
            match read_form(&parts.headers, body, 8192).await {
                Ok(form) => Some(form),
                Err(reply) => return reply,
            }
        } else {
            return plain(StatusCode::METHOD_NOT_ALLOWED, "Method not allowed\n");
        };
        let action = match path {
            access::USERS => admin::Action::Users(form),
            profile::ROUTE => admin::Action::Profile(form),
            _ => admin::Action::Tokens(form, path == access::PERSONAL_TOKENS),
        };
        return site.admin.serve(&parts, peer, &site.database, action).await;
    }
    if path == notifications::ROUTE {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        if !readable {
            return plain(StatusCode::METHOD_NOT_ALLOWED, "Method not allowed\n");
        }
        return site
            .admin
            .serve(&parts, peer, &site.database, admin::Action::Notifications)
            .await;
    }
    if matches!(path, "/admin/" | "/admin/inventaire") && readable {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        return site
            .admin
            .serve(&parts, peer, &site.database, admin::Action::Devices)
            .await;
    }
    if path == overview::ROUTE && readable {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        return site
            .admin
            .serve(
                &parts,
                peer,
                &site.database,
                admin::Action::Overview(site.pxe_ip, site.boot_networks.clone()),
            )
            .await;
    }
    if path == "/presets/pull-github" && parts.method == Method::POST {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        if !admin_origin(&parts.headers, peer, site) {
            return plain(StatusCode::FORBIDDEN, "Invalid origin\n");
        }
        return site
            .admin
            .serve(&parts, peer, &site.database, admin::Action::PullPresets)
            .await;
    }
    if let Some(route) = presets::Route::for_path(path) {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        if !route.allows(&parts.method) {
            return plain(StatusCode::METHOD_NOT_ALLOWED, "Method not allowed\n");
        }
        let form = if readable {
            None
        } else {
            if !admin_origin(&parts.headers, peer, site) {
                return plain(StatusCode::FORBIDDEN, "Invalid origin\n");
            }
            if parts.method == Method::DELETE {
                Some(Bytes::new())
            } else {
                match read_form(&parts.headers, body, route.form_limit()).await {
                    Ok(form) => Some(form),
                    Err(reply) => return reply,
                }
            }
        };
        return site
            .admin
            .serve(
                &parts,
                peer,
                &site.database,
                admin::Action::Preset(route, form),
            )
            .await;
    }
    if (path == "/admin/inventaire/new" && readable)
        || (path == "/admin/inventaire" && parts.method == Method::POST)
    {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        let form = if readable {
            None
        } else {
            if !admin_origin(&parts.headers, peer, site) {
                return plain(StatusCode::FORBIDDEN, "Invalid origin\n");
            }
            match read_form(&parts.headers, body, 1024).await {
                Ok(form) => Some(form),
                Err(reply) => return reply,
            }
        };
        return site
            .admin
            .serve(&parts, peer, &site.database, admin::Action::Register(form))
            .await;
    }
    if let Some(route) = devices::bulk::Route::for_path(path) {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        if !route.allows(&parts.method) {
            return plain(StatusCode::METHOD_NOT_ALLOWED, "Method not allowed\n");
        }
        let form = if readable {
            None
        } else {
            if !admin_origin(&parts.headers, peer, site) {
                return plain(StatusCode::FORBIDDEN, "Invalid origin\n");
            }
            match read_form(&parts.headers, body, 16_384).await {
                Ok(form) => Some(form),
                Err(reply) => return reply,
            }
        };
        return site
            .admin
            .serve(
                &parts,
                peer,
                &site.database,
                admin::Action::Bulk(route, form),
            )
            .await;
    }
    if let Some(view) = jobs::View::for_route(path, parts.uri.query().unwrap_or_default()) {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        if !view.allows(&parts.method) {
            return plain(StatusCode::METHOD_NOT_ALLOWED, "Method not allowed\n");
        }
        if !readable && !admin_origin(&parts.headers, peer, site) {
            return plain(StatusCode::FORBIDDEN, "Invalid origin\n");
        }
        return site
            .admin
            .serve(&parts, peer, &site.database, admin::Action::Jobs(view))
            .await;
    }
    if let Some(id) = devices::linux::route(path) {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        let form = if readable {
            None
        } else if parts.method == Method::POST {
            if !admin_origin(&parts.headers, peer, site) {
                return plain(StatusCode::FORBIDDEN, "Invalid origin\n");
            }
            match read_form(&parts.headers, body, 2048).await {
                Ok(form) => Some(form),
                Err(reply) => return reply,
            }
        } else {
            return plain(StatusCode::METHOD_NOT_ALLOWED, "Method not allowed\n");
        };
        return site
            .admin
            .serve(
                &parts,
                peer,
                &site.database,
                admin::Action::Linux(
                    id,
                    site.assets.http_root().to_owned(),
                    site.rhel_repositories.clone(),
                    form,
                ),
            )
            .await;
    }
    if let Some(route) = devices::route(path) {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        let form = if readable {
            None
        } else if let Some(limit) = route.form_limit(&parts.method) {
            if !admin_origin(&parts.headers, peer, site) {
                return plain(StatusCode::FORBIDDEN, "Invalid origin\n");
            }
            if parts.method == Method::DELETE {
                Some(Bytes::new())
            } else {
                match read_form(&parts.headers, body, limit).await {
                    Ok(form) => Some(form),
                    Err(reply) => return reply,
                }
            }
        } else {
            return plain(StatusCode::METHOD_NOT_ALLOWED, "Method not allowed\n");
        };
        return site
            .admin
            .serve(
                &parts,
                peer,
                &site.database,
                admin::Action::Device(route, form),
            )
            .await;
    }
    if let Some(view) = activity::View::for_route(path) {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        if !view.allows(&parts.method) {
            return plain(StatusCode::METHOD_NOT_ALLOWED, "Method not allowed\n");
        }
        if !readable && !admin_origin(&parts.headers, peer, site) {
            return plain(StatusCode::FORBIDDEN, "Invalid origin\n");
        }
        return site
            .admin
            .serve(
                &parts,
                peer,
                &site.database,
                admin::Action::Activity(view, Arc::clone(&site.catalog)),
            )
            .await;
    }
    if secure
        && readable
        && path == "/init/network"
        && form_urlencoded::parse(parts.uri.query().unwrap_or_default().as_bytes())
            .any(|(name, value)| name == "from" && value == "settings")
    {
        return setup::redirect(StatusCode::FOUND, "/settings/network");
    }
    if onboarding::is_route(path) {
        if !secure || !setup_allowed {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        let endpoint = path.to_owned();
        let form = if parts.method == Method::POST {
            if !admin_origin(&parts.headers, peer, site) {
                return plain(StatusCode::FORBIDDEN, "Invalid origin\n");
            }
            let limit = if matches!(path, "/init/network" | "/init/complete") {
                8192
            } else if path == "/init/images" {
                4096
            } else {
                2048
            };
            match read_form(&parts.headers, body, limit).await {
                Ok(form) => Some(form),
                Err(reply) => return reply,
            }
        } else if readable {
            None
        } else {
            return plain(StatusCode::METHOD_NOT_ALLOWED, "Method not allowed\n");
        };
        return site
            .admin
            .serve(
                &parts,
                peer,
                &site.database,
                admin::Action::Setup(endpoint, form),
            )
            .await;
    }
    if let Some(route) = images::Route::for_path(path, parts.uri.query().unwrap_or_default()) {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        if !route.allows(&parts.method) {
            return plain(StatusCode::METHOD_NOT_ALLOWED, "Method not allowed\n");
        }
        let form = if readable {
            None
        } else {
            if !admin_origin(&parts.headers, peer, site) {
                return plain(StatusCode::FORBIDDEN, "Invalid origin\n");
            }
            if parts.method == Method::DELETE {
                Some(Bytes::new())
            } else {
                let limit = match route {
                    images::Route::List | images::Route::Add => images::MAX_CATALOG_FORM,
                    _ => 8192,
                };
                match read_form(&parts.headers, body, limit).await {
                    Ok(form) => Some(form),
                    Err(reply) => return reply,
                }
            }
        };
        return site
            .admin
            .serve(
                &parts,
                peer,
                &site.database,
                admin::Action::Images(
                    route,
                    form,
                    site.pxe_ip
                        .map(|address| format!("{address}:{https_port}:{}", site.tls.pin())),
                ),
            )
            .await;
    }
    if matches!(
        path,
        certificates::ROUTE | certificates::ACME_ROUTE | presets::ROUTE | winpe::SETTINGS
    ) {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        let form = if parts.method == Method::POST {
            if !admin_origin(&parts.headers, peer, site) {
                return plain(StatusCode::FORBIDDEN, "Invalid origin\n");
            }
            let limit = if matches!(path, certificates::ROUTE | certificates::ACME_ROUTE) {
                certificates::MAX_FORM
            } else if path == presets::ROUTE {
                2048
            } else {
                256
            };
            match read_form(&parts.headers, body, limit).await {
                Ok(form) => Some(form),
                Err(reply) => return reply,
            }
        } else if readable {
            None
        } else {
            return plain(StatusCode::METHOD_NOT_ALLOWED, "Method not allowed\n");
        };
        let action = match (path, form) {
            (certificates::ROUTE, form) => admin::Action::Certificates(site.tls.clone(), form),
            (certificates::ACME_ROUTE, Some(form)) => admin::Action::Acme(site.tls.clone(), form),
            (certificates::ACME_ROUTE, None) => {
                return plain(StatusCode::METHOD_NOT_ALLOWED, "Method not allowed\n")
            }
            (presets::ROUTE, form) => admin::Action::Presets(form),
            (winpe::SETTINGS, form) => admin::Action::Windows(windows_editions(site).await, form),
            _ => unreachable!("matched settings path"),
        };
        return site.admin.serve(&parts, peer, &site.database, action).await;
    }
    if path == "/settings/https" && secure && readable {
        return setup::redirect(StatusCode::FOUND, certificates::ROUTE);
    }
    if let Some(route) = settings::Route::for_path(path) {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        let form = if readable {
            None
        } else if parts.method == Method::POST {
            if !admin_origin(&parts.headers, peer, site) {
                return plain(StatusCode::FORBIDDEN, "Invalid origin\n");
            }
            match read_form(&parts.headers, body, route.form_limit()).await {
                Ok(form) => Some(form),
                Err(reply) => return reply,
            }
        } else {
            return plain(StatusCode::METHOD_NOT_ALLOWED, "Method not allowed\n");
        };
        return site
            .admin
            .serve(
                &parts,
                peer,
                &site.database,
                admin::Action::Settings(route, form),
            )
            .await;
    }
    if path == ansible_webhook::ROUTE {
        if !secure {
            return plain(StatusCode::NOT_FOUND, "Not found\n");
        }
        if parts.method != Method::POST {
            return plain(StatusCode::METHOD_NOT_ALLOWED, "Method not allowed\n");
        }
        let Ok(_permit) = site.requests.try_acquire() else {
            return plain(StatusCode::SERVICE_UNAVAILABLE, "Busy\n");
        };
        let data = match timeout(
            Duration::from_secs(15),
            Limited::new(body, ansible_webhook::BODY_LIMIT).collect(),
        )
        .await
        {
            Ok(Ok(data)) => data.to_bytes(),
            Ok(Err(error)) if error.is::<http_body_util::LengthLimitError>() => {
                return plain(StatusCode::PAYLOAD_TOO_LARGE, "Request body too large\n");
            }
            Ok(Err(_)) => return plain(StatusCode::BAD_REQUEST, "Invalid request body\n"),
            Err(_) => return plain(StatusCode::REQUEST_TIMEOUT, "Request timeout\n"),
        };
        let signature = single(&parts.headers, "x-hub-signature-256")
            .unwrap_or_default()
            .to_owned();
        let event = single(&parts.headers, "x-github-event")
            .unwrap_or_default()
            .to_owned();
        let site = site.clone();
        return match task::spawn_blocking(move || {
            ansible_webhook::receive(&site.database, &signature, &event, &data)
        })
        .await
        {
            Ok(Ok(reply)) => reply,
            _ => plain(StatusCode::SERVICE_UNAVAILABLE, "Webhook unavailable\n"),
        };
    }

    let network = site.boot_network(local, peer);
    let server = if site.boot_networks.is_some() {
        network.map(|network| network.address)
    } else {
        site.pxe_ip
            .filter(|address| local.is_loopback() || local == *address)
    };
    let Some(server) = server else {
        return plain(StatusCode::NOT_FOUND, "Not found\n");
    };
    if readable && path.starts_with(crate::media::RHEL_PREFIX) {
        let Ok(permit) = site.requests.try_acquire() else {
            return plain(StatusCode::SERVICE_UNAVAILABLE, "Busy\n");
        };
        let site_for_check = site.clone();
        let allowed = task::spawn_blocking(move || -> Option<bool> {
            let path = configured_database(&site_for_check.database)?;
            let db = crate::connect(&path, OpenFlags::SQLITE_OPEN_READ_ONLY).ok()?;
            site_for_check.pxe_peer(&db, server, peer).ok()
        })
        .await
        .ok()
        .flatten()
        .unwrap_or(false);
        drop(permit);
        if !allowed {
            return plain(StatusCode::FORBIDDEN, "Client hors du sous-réseau PXE\n");
        }
        return site
            .assets
            .serve_rhel(path, &parts.headers, parts.method == Method::HEAD)
            .await
            .unwrap_or_else(|| plain(StatusCode::NOT_FOUND, "Not found\n"));
    }
    if readable && path.starts_with(linux_boot::PREFIX) {
        let Ok(permit) = site.requests.try_acquire() else {
            return plain(StatusCode::SERVICE_UNAVAILABLE, "Busy\n");
        };
        let site = site.clone();
        let head = parts.method == Method::HEAD;
        let path = path.to_owned();
        let result = task::spawn_blocking(move || {
            if path.starts_with(crate::linux_install::PREFIX) {
                linux_boot::automatic(&site, peer, server, &path, head)
            } else if let Some(route) = linux_boot::route(&path) {
                linux_boot::menu(&site, peer, server, route, head)
            } else {
                Ok(plain(StatusCode::NOT_FOUND, "Not found\n"))
            }
        })
        .await;
        drop(permit);
        return match result {
            Ok(Ok(reply)) => reply,
            _ => plain(StatusCode::SERVICE_UNAVAILABLE, "Menu Linux indisponible\n"),
        };
    }
    let winpe = winpe::allowed(site.winpe_client, peer);
    if winpe && readable {
        if path == winpe::BOOT {
            let editions = windows_editions(site).await;
            if matches!(editions.as_ref(), winpe::Editions::Legacy) {
                return winpe::script(server, site.http_port, None);
            }
            let config = site.database.clone();
            let selected = task::spawn_blocking(move || editions.selected(&config))
                .await
                .ok()
                .flatten();
            return match selected {
                Some(edition) => winpe::script(server, site.http_port, Some(&edition)),
                None => plain(
                    StatusCode::CONFLICT,
                    "Choisissez une édition Windows dans la console\n",
                ),
            };
        }
        if path == "/boot.ipxe" && parts.uri.query() != Some("menu=debian") {
            return winpe::menu(server, site.http_port);
        }
    }
    if readable {
        let editions = if winpe && path.starts_with(winpe::PREFIX) {
            Some(windows_editions(site).await)
        } else {
            None
        };
        if let Some(reply) = site
            .assets
            .serve(
                path,
                &parts.headers,
                parts.method == Method::HEAD,
                editions.as_deref(),
                network,
            )
            .await
        {
            return reply;
        }
    }
    let dynamic = matches!(
        path,
        crate::PRESEED
            | crate::LEGACY_PRESEED
            | crate::GRUB_CONFIG
            | crate::LEGACY_GRUB_CONFIG
            | "/wipe.sh"
    ) || path.starts_with("/api/")
        || path.starts_with("/pxe/postboot/");
    if !dynamic {
        return plain(StatusCode::NOT_FOUND, "Not found\n");
    }

    let Ok(_permit) = site.requests.try_acquire() else {
        return plain(StatusCode::SERVICE_UNAVAILABLE, "Busy\n");
    };
    // Legacy CPL callbacks have smaller limits inside their respective handlers.
    let data = match timeout(
        Duration::from_secs(15),
        Limited::new(body, crate::discovery::MAX_BODY).collect(),
    )
    .await
    {
        Ok(Ok(data)) => data.to_bytes(),
        Ok(Err(error)) if error.is::<http_body_util::LengthLimitError>() => {
            return plain(StatusCode::PAYLOAD_TOO_LARGE, "Request body too large\n");
        }
        Ok(Err(_)) => return plain(StatusCode::BAD_REQUEST, "Invalid request body\n"),
        Err(_) => return plain(StatusCode::REQUEST_TIMEOUT, "Request timeout\n"),
    };
    let uri = parts
        .uri
        .path_and_query()
        .map_or(path, |value| value.as_str())
        .to_owned();
    let method = tiny_http::Method::from_str(parts.method.as_str()).expect("valid HTTP method");
    let headers = parts
        .headers
        .iter()
        .map(|(name, value)| {
            (
                name.to_string(),
                String::from_utf8_lossy(value.as_bytes()).into_owned(),
            )
        })
        .collect();
    let network = network.cloned();
    let site = site.clone();
    let result = task::spawn_blocking(move || {
        let mut body = Cursor::new(data);
        let mut request = PxeRequest {
            uri,
            method,
            headers,
            body: &mut body,
            network: network.as_ref(),
        };
        handle_request(
            &mut request,
            &Routes::Guided(&site.database),
            &site.catalog,
            Some(peer),
        )
    })
    .await;
    let Ok(result) = result else {
        return plain(StatusCode::INTERNAL_SERVER_ERROR, "Request failed\n");
    };
    let status = result.status_code().0;
    let mut reply = Response::builder()
        .status(status)
        .header("X-PXE-Backend", "rust-guided");
    for header in result.headers() {
        reply = reply.header(header.field.as_str().as_str(), header.value.as_str());
    }
    let body = result.into_reader().into_inner();
    let route = match path {
        "/api/discovery" => Some("/api/discovery"),
        crate::PRESEED | crate::LEGACY_PRESEED => Some(path),
        "/api/install-end" => Some("/api/install-end"),
        "/api/machine-ready" => Some("/api/machine-ready"),
        path if path.starts_with("/pxe/postboot/") => Some("/pxe/postboot/"),
        _ => None,
    };
    if let Some(route) = route {
        println!("PXE request peer={peer} route={route} status={status}");
    }
    // Record the actual receipt status and response length, with no bearer token or query values.
    if path == "/api/internal/checkpoint"
        && parts.uri.query().is_some_and(|query| {
            let args = crate::parse_query(query.as_bytes());
            crate::param(&args, "code") == "os_first_boot" && crate::param(&args, "ack") == "1"
        })
    {
        println!(
            "PXE first boot peer={peer} status={status} bytes={}",
            body.len()
        );
    }
    reply.body(bytes(body)).expect("valid application response")
}

async fn read_form(headers: &HeaderMap, body: Incoming, max: usize) -> Result<Bytes, Reply> {
    let is_form = single(headers, CONTENT_TYPE.as_str())
        .and_then(|value| value.split(';').next())
        .is_some_and(|value| {
            value
                .trim()
                .eq_ignore_ascii_case("application/x-www-form-urlencoded")
        });
    if !is_form {
        return Err(plain(
            StatusCode::UNSUPPORTED_MEDIA_TYPE,
            "Expected an HTML form\n",
        ));
    }
    match timeout(Duration::from_secs(5), Limited::new(body, max).collect()).await {
        Ok(Ok(data)) => Ok(data.to_bytes()),
        _ => Err(plain(StatusCode::BAD_REQUEST, "Invalid form data\n")),
    }
}

fn known_dns_name(site: &Site, name: &str) -> bool {
    hostnames::contains(&site.local_names, name) || site.tls.covers_dns_name(name)
}

fn admin_origin(headers: &HeaderMap, peer: IpAddr, site: &Site) -> bool {
    let forwarded = site.admin.trusted_proxy(peer) && headers.contains_key("x-forwarded-host");
    if forwarded {
        admin_origin_on(headers, "x-forwarded-host", site, None)
    } else {
        admin_origin_on(
            headers,
            HOST.as_str(),
            site,
            Some(site.console_port.active()),
        )
    }
}

fn admin_authority(headers: &HeaderMap, header: &str, site: &Site) -> Option<Authority> {
    let host = single(headers, header)
        .filter(|raw| !raw.contains('@'))
        .and_then(|raw| raw.parse::<Authority>().ok())?;
    let name = host.host();
    (name
        .parse::<Ipv4Addr>()
        .is_ok_and(|ip| site.addresses.contains(&ip))
        || name.eq_ignore_ascii_case("localhost")
        || known_dns_name(site, name)
        || site.tls.allowed_acme_host(name)
        || site
            .public_host
            .as_ref()
            .is_some_and(|value| value.eq_ignore_ascii_case(name)))
    .then_some(host)
}

fn admin_origin_on(headers: &HeaderMap, header: &str, site: &Site, port: Option<u16>) -> bool {
    let Some(host) = admin_authority(headers, header, site) else {
        return false;
    };
    let name = host.host();
    let external_port = host.port_u16().unwrap_or(443);
    if port.is_some_and(|port| external_port != port) {
        return false;
    }
    let expected = if external_port == 443 {
        format!("https://{name}")
    } else {
        format!("https://{host}")
    };
    single(headers, ORIGIN.as_str()).is_some_and(|origin| origin.eq_ignore_ascii_case(&expected))
}

fn single<'a>(headers: &'a HeaderMap, name: &str) -> Option<&'a str> {
    let name = HeaderName::from_bytes(name.as_bytes()).ok()?;
    let mut values = headers.get_all(name).iter();
    let first = values.next()?.to_str().ok()?;
    values.next().is_none().then_some(first)
}

fn bytes(value: impl Into<Bytes>) -> Body {
    Full::new(value.into())
        .map_err(|never| match never {})
        .boxed_unsync()
}

fn plain(status: StatusCode, value: &'static str) -> Reply {
    Response::builder()
        .status(status)
        .header("Content-Type", "text/plain; charset=utf-8")
        .body(bytes(value))
        .expect("valid text response")
}
