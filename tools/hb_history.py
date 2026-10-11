#!/usr/bin/env python3
"""記錄每台數位同工的心跳時間（state/hb_history.jsonl），用來量「兩次心跳最長隔多久」。

熊哥 2026-10-12：「心跳改成60分鐘，有那個心跳會超過60分報到的嗎」—— agents.json 只有最後一次心跳，
沒有歷史，回答不了，所以每 5 分鐘把有變動的心跳時間記一行。
用法：python3 tools/hb_history.py           # cron 每 5 分鐘
      python3 tools/hb_history.py --report  # 列出每台最大間隔
"""
import json, os, sys
from datetime import datetime

BASE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "state")
SRC = os.path.join(BASE, "agents.json")
LOG = os.path.join(BASE, "hb_history.jsonl")


def seen():
    last = {}
    if os.path.exists(LOG):
        with open(LOG, encoding="utf-8") as f:
            for line in f:
                try:
                    r = json.loads(line)
                    last.setdefault(r["agent"], []).append(r["ts"])
                except Exception:
                    pass
    return last


def record():
    with open(SRC, encoding="utf-8") as f:
        rows = json.load(f).get("rows", [])
    last = seen()
    with open(LOG, "a", encoding="utf-8") as f:
        for r in rows:
            a, ts = r.get("agent"), r.get("ts")
            if a and ts and ts not in last.get(a, [])[-3:]:
                f.write(json.dumps({"agent": a, "ts": ts, "job": r.get("job")}, ensure_ascii=False) + "\n")


def report():
    for a, tss in sorted(seen().items()):
        t = sorted(datetime.fromisoformat(x) for x in set(tss))
        gaps = [((t[i] - t[i - 1]).total_seconds() / 60, t[i - 1], t[i]) for i in range(1, len(t))]
        if not gaps:
            print(f"{a}: 只有 {len(t)} 筆"); continue
        g, s, e = max(gaps)
        over = sum(1 for x in gaps if x[0] > 60)
        print(f"{a}: {len(t)} 筆、最大間隔 {g:.0f} 分（{s:%m-%d %H:%M} → {e:%H:%M}）、超過 60 分 {over} 次")


if __name__ == "__main__":
    report() if "--report" in sys.argv else record()
