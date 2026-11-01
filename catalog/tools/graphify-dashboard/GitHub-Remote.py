"""Resolve a Graphify source folder to its genuine GitHub repository.

No HTTP requests and no recursive scan through source trees.
Supports a local .git directory, worktree .git file, and tracked child folders.
Untracked sibling directories underneath a common Git root are not conflated.
"""
from pathlib import Path
from urllib.parse import urlsplit, unquote
import re
import subprocess

REPO_PART = re.compile(r"[A-Za-z0-9_.-]+", re.ASCII)


def parse_github_origin(remote):
    """Only expose a safe GitHub owner/repo, never user credentials or arbitrary hosts."""
    if not isinstance(remote, str):
        return None
    remote = remote.strip()
    if remote.startswith("git@github.com:"):
        path = remote[len("git@github.com:"):]
    else:
        url = urlsplit(remote)
        if url.scheme not in ("https", "http", "ssh", "git") or (url.hostname or "").lower() != "github.com":
            return None
        path = url.path.lstrip("/")
    path = unquote(path).rstrip("/")
    if path.lower().endswith(".git"):
        path = path[:-4]
    parts = path.split("/")
    if len(parts) != 2 or not all(REPO_PART.fullmatch(part) for part in parts):
        return None
    if any(part in (".", "..") for part in parts):
        return None
    return "/".join(parts)


def _git(directory, *args):
    try:
        result = subprocess.run(
            ["git", "-C", str(directory), *args],
            capture_output=True, text=True, encoding="utf-8", errors="replace",
            timeout=5, check=False,
        )
        return result.stdout.strip() if result.returncode == 0 else None
    except (OSError, subprocess.TimeoutExpired):
        return None


def github_repository_for_source(source):
    """Return GitHub owner/repo only for the actual repository containing source."""
    if not isinstance(source, str) or not source.strip():
        return None
    folder = Path(source.strip())
    if not folder.is_dir():
        return None
    folder = folder.resolve()
    # Find actual Git metadata, not arbitrary source code referencing a GitHub URL.
    # Walk upward, never recurse down into unrelated nested repositories.
    git_root = next((parent for parent in (folder, *folder.parents)
                     if (parent / ".git").is_dir() or (parent / ".git").is_file()), None)
    if git_root is None:
        return None
    # A Git worktree's .git is a text file; git -C resolves it correctly.
    toplevel = _git(folder, "rev-parse", "--show-toplevel")
    if not toplevel:
        return None
    try:
        top = Path(toplevel).resolve()
        rel = folder.relative_to(top)
    except (ValueError, OSError):
        return None
    # A subdirectory of an umbrella repo is only a valid Git source if Git
    # actually tracks files there; avoids incorrectly linking untracked siblings.
    if rel.parts:
        try:
            process = subprocess.Popen(
                ["git", "-C", str(top), "ls-files", "-z", "--", rel.as_posix()],
                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
            first = process.stdout.read(1) if process.stdout else b""
            if process.stdout:
                process.stdout.close()
            if first:
                # We only need to know that at least one tracked path exists.
                # Stopping the command avoids materializing a huge source-tree listing.
                process.terminate()
            process.wait(timeout=5)
            if not first:
                return None
        except (OSError, subprocess.TimeoutExpired):
            return None
    return parse_github_origin(_git(folder, "config", "--local", "--get", "remote.origin.url"))
