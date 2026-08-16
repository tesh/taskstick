#!/usr/bin/env python3
"""
Deploy TaskStick to IONOS via SFTP.

Usage:
    python3 deploy.py path/to/file.php [path/to/other/file.php ...]
    python3 deploy.py --all                # deploy every git-tracked file
    python3 deploy.py --all --dry-run      # preview without uploading

Credentials come from deploy_local.py (git-ignored). Copy
deploy_local.py.example to deploy_local.py and fill in real values before
running this.
"""
import argparse
import subprocess
import sys
from pathlib import Path

try:
    import paramiko
except ImportError:
    sys.exit("paramiko not installed — run: pip install paramiko")

try:
    from deploy_local import SFTP_HOST, SFTP_USER, SFTP_PASS, SFTP_PORT, REMOTE_ROOT
except ImportError:
    sys.exit(
        "Missing deploy_local.py — copy deploy_local.py.example to "
        "deploy_local.py and fill in real values."
    )

ROOT = Path(__file__).resolve().parent


def git_tracked_files():
    out = subprocess.run(
        ["git", "ls-files"], cwd=ROOT, capture_output=True, text=True, check=True
    )
    return [ROOT / p for p in out.stdout.splitlines() if p]


def remote_mkdir_p(sftp, remote_dir: str):
    parts = remote_dir.strip("/").split("/")
    path = ""
    for part in parts:
        path += "/" + part
        try:
            sftp.stat(path)
        except FileNotFoundError:
            sftp.mkdir(path)


def upload(sftp, local_path: Path):
    rel = local_path.relative_to(ROOT)
    remote_path = f"{REMOTE_ROOT}/{rel.as_posix()}"
    remote_mkdir_p(sftp, str(Path(remote_path).parent))
    sftp.put(str(local_path), remote_path)
    print(f"uploaded {rel} -> {remote_path}")


def main():
    parser = argparse.ArgumentParser(description="Deploy TaskStick files to IONOS via SFTP.")
    parser.add_argument("files", nargs="*", help="File paths to deploy, relative to repo root.")
    parser.add_argument("--all", action="store_true", help="Deploy every git-tracked file.")
    parser.add_argument("--dry-run", action="store_true", help="Print what would be uploaded without uploading.")
    args = parser.parse_args()

    if args.all:
        targets = git_tracked_files()
    elif args.files:
        targets = [(ROOT / f).resolve() for f in args.files]
    else:
        parser.error("Specify one or more file paths, or --all")

    targets = [t for t in targets if t.is_file()]
    if not targets:
        sys.exit("No files to deploy.")

    if args.dry_run:
        for t in targets:
            print(f"[dry-run] would upload {t.relative_to(ROOT)}")
        return

    transport = paramiko.Transport((SFTP_HOST, SFTP_PORT))
    transport.connect(username=SFTP_USER, password=SFTP_PASS)
    sftp = paramiko.SFTPClient.from_transport(transport)
    try:
        for t in targets:
            upload(sftp, t)
    finally:
        sftp.close()
        transport.close()


if __name__ == "__main__":
    main()
