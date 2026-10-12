#!/usr/bin/env python3
"""小芯開發中專案狀態（戰情室卡片；Asana 1219411554379151）。

熊哥 2026-10-12：「我需要你能在戰情室中，回報你目前開發專案的狀態」。
- state/projects.json：小芯手動維護（每個專案正在做的事、階段、卡在誰、連結）
- 本程式每 10 分鐘補上「自動」欄位：正式站目前版本（version_api 或 version_file）、測試站目前分支與 commit（test_dir）
- 輸出 state/projects_view.json 給 lib/projects.php 讀
cron：*/10 * * * * python3 /srv/dev/ops.ccnda.net/tools/projects_status.py
"""
import json, os, subprocess, urllib.request
from datetime import datetime, timezone, timedelta

BASE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "state")
SRC = os.path.join(BASE, "projects.json")
OUT = os.path.join(BASE, "projects_view.json")
TZ = timezone(timedelta(hours=8))


def prod_version(url):
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0 (ccnda-ops)"})
        d = json.load(urllib.request.urlopen(req, timeout=10))
        return (d.get("data") or {}).get("version")
    except Exception:
        return None


def test_branch(path):
    try:
        git = ["git", "-C", path]
        br = subprocess.run(git + ["branch", "--show-current"], capture_output=True, text=True, timeout=10).stdout.strip()
        log = subprocess.run(git + ["log", "-1", "--format=%h|%cI|%s"], capture_output=True, text=True, timeout=10).stdout.strip()
        h, when, subj = (log.split("|", 2) + ["", "", ""])[:3]
        return {"branch": br or "(detached)", "commit": h, "when": when, "subject": subj[:80]}
    except Exception:
        return None


def main():
    with open(SRC, encoding="utf-8") as f:
        projects = json.load(f)
    for p in projects:
        if p.get("version_api"):
            p["prod_version"] = prod_version(p["version_api"])
        if p.get("version_file"):
            try:
                p["prod_version"] = open(p["version_file"], encoding="utf-8").read().strip()
            except Exception:
                p["prod_version"] = None
        if p.get("test_dir"):
            p["test"] = test_branch(p["test_dir"])
    view = {"checked_at": datetime.now(TZ).isoformat(timespec="seconds"), "projects": projects}
    tmp = OUT + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(view, f, ensure_ascii=False, indent=1)
    os.replace(tmp, OUT)


if __name__ == "__main__":
    main()
