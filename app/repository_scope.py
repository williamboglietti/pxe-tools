"""A manifest-driven Git checkout for PXE, without shell commands or resets.

Paths are read from committed Git trees, not from a mutable worktree manifest.
The manifest declares dependencies; every mapped playbook must be included in
its paths. This module does not infer dynamic Ansible dependencies.
"""

from __future__ import annotations

import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import tempfile
from contextlib import contextmanager


class ScopeError(ValueError):
    """An invalid profile or a checkout that cannot safely change scope."""


_PATH_SEGMENT = re.compile(r"[A-Za-z0-9_.-]+\Z")
_ROLE = re.compile(r"[A-Za-z0-9][A-Za-z0-9_-]{0,63}\Z")
_REDIRECT_ENV = {"GIT_DIR", "GIT_WORK_TREE", "GIT_INDEX_FILE", "GIT_COMMON_DIR"}
_BOOTSTRAP_MARKER = "pxe-scope-bootstrap.json"


def _environment(env):
    result = os.environ.copy()
    if env is not None:
        result.update(env)
    for name in _REDIRECT_ENV:
        result.pop(name, None)
    return result


def _log(log, message):
    if log is not None:
        log(message)


def _git(workdir, args, env=None, *, input_data=None, allowed=(0,)):
    command = ["git"]
    if workdir is not None:
        command += ["-c", "safe.directory=" + str(workdir), "-C", str(workdir)]
    result = subprocess.run(command + args, input=input_data, capture_output=True,
                            env=_environment(env), check=False)
    if result.returncode not in allowed:
        # Git's stderr or argv can contain an authenticated remote URL.
        raise ScopeError(f"Git {args[0]} failed (exit {result.returncode}); checkout preserved")
    return result


def _path(value, field):
    if not isinstance(value, str) or not value or len(value) > 512 or value.startswith("/") or ".." in value:
        raise ScopeError(f"{field} must be a relative repository path")
    parts = value.split("/")
    if any(part in {"", ".", ".."} or part.casefold() == ".git"
           or not _PATH_SEGMENT.fullmatch(part) for part in parts):
        raise ScopeError(f"{field} contains an unsafe path or Git pattern")
    return value


def _branch(branch, env=None):
    if not isinstance(branch, str) or len(branch) > 255 or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_./-]*", branch):
        raise ScopeError("branch must be a literal branch name")
    _git(None, ["check-ref-format", "--branch", branch], env)
    return branch


def _repository(workdir, env=None, *, mutable=True):
    root = Path(workdir).resolve()
    if not root.is_dir() or not (root / ".git").exists():
        raise ScopeError("a standalone Git checkout is required; preserve this directory and clone separately")
    if mutable and (not (root / ".git").is_dir() or (root / ".git").is_symlink()):
        raise ScopeError("linked worktrees are read-only here; preserve them and clone separately")
    top = _git(root, ["rev-parse", "--show-toplevel"], env).stdout.decode().strip()
    if Path(top).resolve() != root:
        raise ScopeError("workdir must be the Git checkout root")
    worktrees = _git(root, ["worktree", "list", "--porcelain"], env).stdout
    if mutable and sum(line.startswith(b"worktree ") for line in worktrees.splitlines()) != 1:
        raise ScopeError("shared worktrees are not supported; preserve them and use a separate scoped clone")
    if mutable and b"origin" not in _git(root, ["remote"], env).stdout.splitlines():
        raise ScopeError("the checkout needs an origin remote")
    return root


def _commit(root, ref, env):
    if not isinstance(ref, str) or not ref or "\0" in ref:
        raise ScopeError("invalid profile reference")
    value = _git(root, ["rev-parse", "--verify", "--end-of-options", ref + "^{commit}"], env).stdout.decode().strip()
    if not re.fullmatch(r"[0-9a-f]{40,64}", value):
        raise ScopeError("profile reference did not resolve to a commit")
    return value


def _tree(root, commit, env):
    result = {}
    for record in _git(root, ["ls-tree", "-r", "-t", "-z", "--full-tree", commit], env).stdout.split(b"\0"):
        if not record:
            continue
        meta, path = record.split(b"\t", 1)
        mode, kind, oid = meta.decode("ascii").split()
        result[os.fsdecode(path)] = (mode, kind, oid)
    return result


def _covered(path, scopes, tree):
    return any(path == scope or (tree.get(scope, ("", "", ""))[1] == "tree" and path.startswith(scope + "/"))
               for scope in scopes)


def _json_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ScopeError("duplicate profile fields are not allowed")
        result[key] = value
    return result


def _profile(root, profile_path, ref, env):
    profile_path = _path(profile_path, "profile_path")
    if not profile_path.endswith(".json"):
        raise ScopeError("profile_path must name a JSON manifest")
    commit = _commit(root, ref, env)
    tree = _tree(root, commit, env)
    entry = tree.get(profile_path)
    if entry is None:
        raise ScopeError(f"profile {profile_path} is missing at the candidate commit")
    if entry[0] not in {"100644", "100755"} or entry[1] != "blob":
        raise ScopeError("the profile must be a tracked regular file")
    raw = _git(root, ["show", commit + ":" + profile_path], env).stdout
    if len(raw) > 128 * 1024:
        raise ScopeError("profile is too large")
    try:
        # bytes input lets json.loads autodetect UTF-16/32 and strip a UTF-8
        # BOM. PHP's validator accepts only the canonical UTF-8 representation.
        data = json.loads(raw.decode("utf-8"), object_pairs_hook=_json_object)
    except (UnicodeError, json.JSONDecodeError, RecursionError) as exc:
        raise ScopeError("profile is not valid UTF-8 JSON") from exc
    fields = {"schema_version", "name", "presets_path", "paths", "playbooks"}
    if not isinstance(data, dict) or not fields.issubset(data):
        raise ScopeError("profile needs schema_version, name, presets_path, paths and playbooks")
    if type(data["schema_version"]) is not int or data["schema_version"] != 1:
        raise ScopeError("only schema_version 1 is supported")
    name = data["name"]
    if not isinstance(name, str) or not name.strip(" \t\n\r\0\v") or re.search(r"[\x00-\x1f\x7f]", name):
        raise ScopeError("profile name must be nonempty and contain no control characters")
    try:
        valid_name = len(name.encode("utf-8")) <= 80
    except UnicodeError:
        valid_name = False
    if not valid_name:
        raise ScopeError("profile name must be valid UTF-8 and at most 80 bytes")
    presets = _path(data["presets_path"], "presets_path")
    paths = data["paths"]
    if not isinstance(paths, list) or not paths:
        raise ScopeError("paths must be a nonempty list")
    paths = [_path(path, "paths") for path in paths]
    if len(set(paths)) != len(paths):
        raise ScopeError("duplicate scope paths are not allowed")
    playbooks = data["playbooks"]
    if not isinstance(playbooks, dict) or not playbooks:
        raise ScopeError("playbooks must be a nonempty role-to-path mapping")
    for role, path in playbooks.items():
        if not isinstance(role, str) or not _ROLE.fullmatch(role) or role.isdecimal():
            raise ScopeError("invalid playbook role")
        playbooks[role] = _path(path, "playbooks")
    # Git has no empty directories. The writable preset output can disappear
    # after the last deletion, and must remain usable for push retries/new JSON.
    for parent in PurePosixPath(presets).parents:
        ancestor = parent.as_posix()
        if ancestor != "." and ancestor in tree and tree[ancestor][1] != "tree":
            raise ScopeError("presets_path has a non-directory parent")
    if presets not in tree:
        tree[presets] = ("040000", "tree", "")
    scopes = sorted(set(paths + [profile_path, presets]))
    for scope in scopes:
        if scope not in tree:
            raise ScopeError(f"scope path {scope} is missing at the candidate commit")
    if tree[presets][1] != "tree":
        raise ScopeError("presets_path must designate a directory")
    if not _covered(presets, paths, tree):
        raise ScopeError("presets_path must be included by paths, including when the output is empty")
    for path, (mode, kind, _) in tree.items():
        if _covered(path, scopes, tree) and (mode == "120000" or kind == "commit"):
            raise ScopeError("scoped paths cannot contain symlinks or submodules")
    for path in playbooks.values():
        if path not in tree or tree[path][1] != "blob" or tree[path][0] not in {"100644", "100755"}:
            raise ScopeError("a mapped playbook is missing or is not a regular file")
        if not path.endswith((".yml", ".yaml")) or not _covered(path, paths, tree):
            raise ScopeError("every mapped playbook must be included by paths")
    profile = {"schema_version": 1, "name": name.strip(" \t\n\r\0\v"), "profile_path": profile_path,
               "presets_path": presets, "paths": paths, "playbooks": dict(playbooks),
               "scope_paths": scopes}
    return profile, tree


def load_profile(workdir, profile_path, env=None, *, ref="HEAD", log=None):
    """Validate the profile from Git at ref; no checkout or configuration writes."""
    root = _repository(workdir, env, mutable=False)
    profile, _ = _profile(root, profile_path, ref, env)
    _log(log, "PXE profile validated from a committed tree")
    return profile


def _mark_bootstrap(root, branch, env):
    """Only a newly created --no-checkout clone receives this private marker."""
    path = root / ".git" / _BOOTSTRAP_MARKER
    data = {"schema_version": 1, "kind": "pxe-no-checkout", "branch": branch,
            "head": _commit(root, "HEAD", env)}
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as output:
        json.dump(data, output, sort_keys=True)
        output.flush()
        os.fsync(output.fileno())


def _can_resume_bootstrap(root, branch, env):
    path = root / ".git" / _BOOTSTRAP_MARKER
    if path.is_symlink() or not path.is_file():
        raise ScopeError("missing index has no valid PXE bootstrap marker; preserve this checkout and clone separately")
    try:
        if path.stat().st_size > 4096:
            raise ScopeError("invalid PXE bootstrap marker")
        marker = json.loads(path.read_bytes(), object_pairs_hook=_json_object)
    except (OSError, UnicodeError, json.JSONDecodeError, RecursionError) as error:
        raise ScopeError("invalid PXE bootstrap marker; checkout preserved") from error
    if (not isinstance(marker, dict) or set(marker) != {"schema_version", "kind", "branch", "head"}
            or type(marker["schema_version"]) is not int or marker["schema_version"] != 1
            or marker["kind"] != "pxe-no-checkout" or marker["branch"] != branch
            or marker["head"] != _commit(root, "HEAD", env)):
        raise ScopeError("PXE bootstrap HEAD or branch changed; preserve local work and clone separately")
    return True


def _finish_bootstrap(root):
    (root / ".git" / _BOOTSTRAP_MARKER).unlink(missing_ok=True)


def _guard_checkout(root, branch, env, *, bootstrap=False):
    current = _git(root, ["symbolic-ref", "--quiet", "--short", "HEAD"], env, allowed=(0, 1)).stdout.decode().strip()
    if current != branch:
        raise ScopeError("branch differs or HEAD is detached; preserve local commits and create a validated scoped clone")
    operations = ["MERGE_HEAD", "CHERRY_PICK_HEAD", "REVERT_HEAD", "rebase-merge", "rebase-apply"]
    if any((root / ".git" / name).exists() for name in operations):
        raise ScopeError("finish the existing Git operation before changing scope")
    if bootstrap:
        if os.path.lexists(root / ".git" / "index") or any(path.name != ".git" for path in root.iterdir()):
            raise ScopeError("bootstrap requires a fresh clone without a checkout")
        return True
    if not os.path.lexists(root / ".git" / "index"):
        return _can_resume_bootstrap(root, branch, env)
    if _git(root, ["status", "--porcelain=v1", "-z", "--untracked-files=no"], env).stdout:
        raise ScopeError("tracked files or index are dirty; preserve them before changing scope")
    # Git status can ignore physical files with skip-worktree set.
    for item in _git(root, ["ls-files", "-v", "-z"], env).stdout.split(b"\0"):
        if item.startswith(b"S ") and os.path.lexists(root / os.fsdecode(item[2:])):
            raise ScopeError("a tracked file exists outside the current sparse scope; preserve it before changing scope")
    return False


def _untracked(root, env):
    paths = set()
    for args in (["ls-files", "--others", "--exclude-standard", "-z"],
                 ["ls-files", "--others", "--ignored", "--exclude-standard", "-z"]):
        paths.update(os.fsdecode(item) for item in _git(root, args, env).stdout.split(b"\0") if item)
    return paths


def _guard_targets(root, profile, tree, env):
    ignored_case = _git(root, ["config", "--get", "core.ignorecase"], env, allowed=(0, 1)).stdout.strip() == b"true"
    canonical = str.casefold if ignored_case else lambda value: value
    extras = {canonical(path) for path in _untracked(root, env)}
    targets = [path for path in tree if tree[path][1] == "blob" and _covered(path, profile["scope_paths"], tree)]
    for path in targets:
        key = canonical(path)
        if any(key == extra or key.startswith(extra + "/") or extra.startswith(key + "/") for extra in extras):
            raise ScopeError("a scope target would overwrite an untracked or ignored file; preserve it before changing scope")
    for path in targets + profile["scope_paths"]:
        current = root
        for part in PurePosixPath(path).parts:
            current /= part
            if current.is_symlink():
                raise ScopeError("a scope target or parent is a filesystem symlink")
            if current != root / path and current.exists() and not current.is_dir():
                raise ScopeError("a scope target parent is not a directory")
        if tree.get(path, ("", "", ""))[1] == "tree" and current.exists() and not current.is_dir():
            raise ScopeError("a scope directory is occupied by a filesystem file")


def _patterns(profile, tree):
    scopes = profile["scope_paths"]
    directories = [path for path in scopes if tree[path][1] == "tree"]
    leaves = [path for path in scopes if not any(path != parent and path.startswith(parent + "/") for parent in directories)]
    parents = set()
    for path in leaves:
        parts = path.split("/")
        parents.update("/".join(parts[:index]) for index in range(1, len(parts)))
    patterns = ["!/*"]
    for path in sorted(parents, key=lambda value: (value.count("/"), value)):
        patterns += ["/" + path + "/", "!/" + path + "/*"]
    patterns += ["/" + path + ("/" if tree[path][1] == "tree" else "") for path in sorted(leaves)]
    return ("\n".join(patterns) + "\n").encode("utf-8")


def _configure(root, branch, env):
    settings = {
        "core.repositoryformatversion": "1", "extensions.partialClone": "origin",
        "remote.origin.promisor": "true", "remote.origin.partialclonefilter": "blob:none",
        "remote.origin.fetch": f"+refs/heads/{branch}:refs/remotes/origin/{branch}",
        "remote.origin.tagOpt": "--no-tags",
        f"branch.{branch}.remote": "origin", f"branch.{branch}.merge": f"refs/heads/{branch}",
        "remote.origin.push": f"refs/heads/{branch}:refs/heads/{branch}", "push.default": "simple",
    }
    for key, value in settings.items():
        _git(root, ["config", "--local", "--replace-all", key, value], env)


@contextmanager
def _preserve_untracked(root, env):
    """Git may remove ignored files when directories leave a sparse scope."""
    paths = _untracked(root, env)
    if not paths:
        yield
        return
    backup = Path(tempfile.mkdtemp(prefix="pxe-scope-preserved-"))
    records = []
    parents = {}
    mutating = False
    restored = False

    def restore_owner(path, info):
        if os.geteuid() == 0:
            os.chown(path, info.st_uid, info.st_gid, follow_symlinks=False)

    try:
        for index, path in enumerate(sorted(paths)):
            parts = PurePosixPath(path).parts
            if not parts or any(part in {".", "..", ".git"} for part in parts) or path.startswith("/"):
                raise ScopeError("unsafe untracked path")
            source = root / path
            info = source.lstat()
            current = root
            for part in parts[:-1]:
                current /= part
                if current.is_symlink() or not current.is_dir():
                    raise ScopeError("an untracked file parent is not a regular directory")
                parents[current.relative_to(root).as_posix()] = current.stat()
            if stat.S_ISLNK(info.st_mode):
                records.append((path, None, os.readlink(source), info))
            elif stat.S_ISREG(info.st_mode):
                saved = backup / str(index)
                shutil.copy2(source, saved, follow_symlinks=False)
                records.append((path, saved, None, info))
            else:
                raise ScopeError("unsupported untracked filesystem object; preserve it before changing scope")
        mutating = True
        try:
            yield
        finally:
            for path, info in sorted(parents.items(), key=lambda item: (item[0].count("/"), item[0])):
                directory = root / path
                if directory.is_symlink() or (directory.exists() and not directory.is_dir()):
                    raise ScopeError(f"cannot restore preserved files safely; protected backup retained at {backup}")
                if not directory.exists():
                    directory.mkdir(mode=stat.S_IMODE(info.st_mode))
                    restore_owner(directory, info)
                    directory.chmod(stat.S_IMODE(info.st_mode))
            for path, saved, link, info in records:
                destination = root / path
                current = root
                for part in PurePosixPath(path).parts[:-1]:
                    current /= part
                    if current.is_symlink() or (current.exists() and not current.is_dir()):
                        raise ScopeError(f"cannot restore preserved files safely; protected backup retained at {backup}")
                destination.parent.mkdir(parents=True, exist_ok=True)
                if link is not None:
                    if destination.is_symlink() and os.readlink(destination) == link:
                        continue
                    if os.path.lexists(destination):
                        raise ScopeError(f"preserved file conflict; protected backup retained at {backup}")
                    destination.symlink_to(link)
                    restore_owner(destination, info)
                else:
                    if destination.is_symlink() or destination.is_dir():
                        raise ScopeError(f"preserved file conflict; protected backup retained at {backup}")
                    # Surviving files can have been updated concurrently; keep them.
                    if not destination.exists():
                        shutil.copy2(saved, destination)
                        restore_owner(destination, info)
                        destination.chmod(stat.S_IMODE(info.st_mode))
            restored = True
    finally:
        # Retain private backups only when restoration itself was interrupted.
        if not mutating or restored:
            shutil.rmtree(backup)


def _set_scope(root, profile, tree, env):
    _git(root, ["sparse-checkout", "set", "--no-cone", "--stdin"], env,
         input_data=_patterns(profile, tree))


def apply_scope(workdir, profile_path, branch="main", env=None, *, log=None):
    """Apply the committed HEAD manifest to an existing clean branch, idempotently."""
    branch = _branch(branch, env)
    root = _repository(workdir, env)
    cold = _guard_checkout(root, branch, env)
    profile, tree = _profile(root, profile_path, "HEAD", env)
    _guard_targets(root, profile, tree, env)
    with _preserve_untracked(root, env):
        _configure(root, branch, env)
        _set_scope(root, profile, tree, env)
        if cold:
            _git(root, ["read-tree", "-m", "-u", "HEAD"], env)
    _finish_bootstrap(root)
    _log(log, "PXE sparse scope applied; untracked and ignored files preserved")
    return profile


def clone_scoped(url, workdir, branch="main", profile_path="pxe/profile.json", env=None, *, log=None):
    """Create a no-checkout, single-branch partial clone, then its validated scope."""
    branch = _branch(branch, env)
    destination = Path(workdir).absolute()
    if destination.exists() and (not destination.is_dir() or any(destination.iterdir())):
        raise ScopeError("clone destination must be absent or empty")
    _log(log, "Cloning one branch with blob:none and no initial checkout")
    _git(None, ["clone", "--no-checkout", "--single-branch", "--filter=blob:none", "--no-tags",
                "--branch", branch, "--", str(url), str(destination)], env)
    root = _repository(destination, env)
    _guard_checkout(root, branch, env, bootstrap=True)
    _mark_bootstrap(root, branch, env)
    profile, tree = _profile(root, profile_path, "HEAD", env)
    _guard_targets(root, profile, tree, env)
    _configure(root, branch, env)
    _set_scope(root, profile, tree, env)
    # set configures patterns, but a --no-checkout clone has no populated index.
    _git(root, ["read-tree", "-m", "-u", "HEAD"], env)
    _finish_bootstrap(root)
    _log(log, "Validated PXE sparse clone ready")
    return profile


def pull_scoped(workdir, branch="main", profile_path="pxe/profile.json", env=None, *, log=None):
    """Fetch filtered, validate the candidate manifest, then fast-forward in scope.

    A local-ahead checkout remains at HEAD so a previously failed preset push can
    be retried. Divergence, dirty tracked files and branch switches are refused.
    """
    branch = _branch(branch, env)
    root = _repository(workdir, env)
    cold = _guard_checkout(root, branch, env)
    _configure(root, branch, env)
    ref = f"refs/remotes/origin/{branch}"
    _log(log, "Fetching one branch with blob:none")
    _git(root, ["fetch", "--no-tags", "--filter=blob:none", "origin",
                f"+refs/heads/{branch}:{ref}"], env)
    head = _commit(root, "HEAD", env)
    remote = _commit(root, ref, env)
    forward = _git(root, ["merge-base", "--is-ancestor", head, remote], env, allowed=(0, 1)).returncode == 0
    ahead = _git(root, ["merge-base", "--is-ancestor", remote, head], env, allowed=(0, 1)).returncode == 0
    if not forward and not ahead:
        raise ScopeError("branches diverged; local commits preserved, no reset or merge performed")
    candidate = remote if forward else head
    profile, tree = _profile(root, profile_path, candidate, env)
    _guard_targets(root, profile, tree, env)
    if cold:
        # Populate only HEAD files admitted by the candidate patterns. Neither
        # the old files nor new targets may overwrite data added between tries.
        _guard_targets(root, profile, _tree(root, head, env), env)
    with _preserve_untracked(root, env):
        _set_scope(root, profile, tree, env)
        if cold:
            _log(log, "Resuming the unchanged marked PXE no-checkout clone")
            _git(root, ["read-tree", "-m", "-u", "HEAD"], env)
        if candidate != head:
            _log(log, "Candidate profile validated; fast-forwarding within its sparse scope")
            # Diffstat reads even excluded blobs; disable it for partial clones.
            _git(root, ["merge", "--ff-only", "--no-overwrite-ignore", "--no-stat", ref], env)
        else:
            _log(log, "Local HEAD retained, including any unpublished commits")
        profile, tree = _profile(root, profile_path, "HEAD", env)
        _set_scope(root, profile, tree, env)
    _finish_bootstrap(root)
    _log(log, "PXE scoped pull complete; untracked and ignored files preserved")
    return profile
