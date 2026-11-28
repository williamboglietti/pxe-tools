// SPDX-License-Identifier: LGPL-3.0-only

use super::{
    access::{Account, Permission},
    jobs::json_reply,
    ErrorBox, Reply,
};
use crate::apt_updates::{self, State};
use hyper::StatusCode;
use rusqlite::{params, Connection};
use serde::Serialize;

pub(super) const ROUTE: &str = "/admin/notifications";
const LIMIT: u32 = 20;

// Each source already has a stable identity and an authoritative end state.
// Keep raw job logs, boot payloads and action credentials out of the response.
const RECENT: &str = "
WITH recent(kind,id,machine,task,status,at) AS (
  SELECT 'boot',e.id,e.machine_id,NULL,'info',e.created_at
  FROM machine_events e
  WHERE ?1 AND e.checkpoint='pxe_boot_observed' AND e.created_at >= datetime('now','-7 days')
    AND NOT EXISTS (
      SELECT 1 FROM machine_events prior
      WHERE prior.machine_id=e.machine_id AND prior.checkpoint=e.checkpoint
        AND prior.id < e.id AND prior.created_at >= datetime(e.created_at,'-1 minute')
    )
  UNION ALL
  SELECT 'discovery',id,machine_id,NULL,'success',ended_at FROM discoveries
  WHERE ?1 AND discovery_status='success' AND ended_at >= datetime('now','-7 days')
  UNION ALL
  SELECT 'disk',id,machine_id,action_id,job_status,ended_at FROM disk_tests
  WHERE ?2 AND job_status IN ('success','failed') AND ended_at >= datetime('now','-7 days')
  UNION ALL
  SELECT 'deployment',id,machine_id,id,status,ended_at FROM actions
  WHERE ?2 AND type IN ('install','install_only','reinstall') AND status IN ('success','failed')
    AND ended_at >= datetime('now','-7 days')
)
SELECT r.kind,r.id,r.machine,r.task,r.status,unixepoch(r.at),
       COALESCE(NULLIF(TRIM(m.hostname),''),m.mac)
FROM recent r JOIN machines m ON m.id=r.machine
WHERE unixepoch(r.at) IS NOT NULL
ORDER BY unixepoch(r.at) DESC,r.id DESC,r.kind
LIMIT ?3";

#[derive(Serialize)]
struct Notice {
    key: String,
    title: &'static str,
    detail: String,
    href: String,
    tone: &'static str,
    at: Option<i64>,
}

pub(super) fn available_to(account: &Account) -> bool {
    account.allows(Permission::DevicesRead)
        || account.allows(Permission::ActivityRead)
        || updates_for(account)
}

pub(super) fn updates_for(account: &Account) -> bool {
    account.admin && apt_updates::enabled()
}

pub(super) fn feed(db: &Connection, account: &Account) -> Result<Reply, ErrorBox> {
    let mut notices = Vec::new();
    if updates_for(account) {
        if let Some(notice) = update(&State::read(db)?) {
            notices.push(notice);
        }
    }
    notices.extend(recent(
        db,
        account.allows(Permission::DevicesRead),
        account.allows(Permission::ActivityRead),
    )?);
    json_reply(StatusCode::OK, notices)
}

fn update(state: &State) -> Option<Notice> {
    if state.phase.pending() {
        return None;
    }
    let versions = [
        ("pxe-tools", "PXE Tools"),
        ("pxe-tools-ansible", "Module Ansible"),
    ]
    .into_iter()
    .filter_map(|(package, label)| {
        state
            .candidates
            .get(package)
            .map(|version| format!("{label} {version}"))
    })
    .collect::<Vec<_>>()
    .join(" · ");
    (!versions.is_empty()).then(|| Notice {
        key: format!("update:{versions}"),
        title: "Mise à jour disponible",
        detail: versions,
        href: super::updates::ROUTE.to_owned(),
        tone: "info",
        at: (state.checked_at > 0).then_some(state.checked_at),
    })
}

fn recent(db: &Connection, devices: bool, activity: bool) -> rusqlite::Result<Vec<Notice>> {
    let mut statement = db.prepare(RECENT)?;
    let rows = statement.query_map(params![devices, activity, LIMIT], |row| {
        let kind: String = row.get(0)?;
        let id: i64 = row.get(1)?;
        let machine: i64 = row.get(2)?;
        let task: Option<i64> = row.get(3)?;
        let status: String = row.get(4)?;
        let at: i64 = row.get(5)?;
        let title = match (kind.as_str(), status.as_str()) {
            ("boot", _) => "Démarrage PXE détecté",
            ("discovery", _) => "Inventaire reçu",
            ("disk", "success") => "Test disque terminé",
            ("disk", _) => "Échec du test disque",
            ("deployment", "success") => "Provisionnement terminé",
            _ => "Échec du provisionnement",
        };
        let href = if matches!(kind.as_str(), "boot" | "discovery") {
            format!("/admin/inventaire/{machine}")
        } else if let Some(task) = task {
            format!("/tasks/{task}")
        } else {
            format!("/admin/inventaire/{machine}/tasks")
        };
        Ok(Notice {
            key: format!("{kind}:{id}:{at}:{status}"),
            title,
            detail: row.get(6)?,
            href,
            tone: match status.as_str() {
                "success" => "success",
                "failed" => "error",
                _ => "info",
            },
            at: Some(at),
        })
    })?;
    rows.collect()
}
