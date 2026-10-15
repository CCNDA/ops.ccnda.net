#!/usr/bin/env python3
"""戰情室 Linode 主機監控：每 5 分鐘用 Linode API v4（唯讀權杖）取各主機狀態與最近數據，
寫 state/linode.json（頁面只讀這個檔，不碰權杖）。
熊哥 2026-10-12：「戰情室還有設備監控和 Linode 監控沒做…也是要儘快完成」（Asana 1219409196970109）。

- 權杖：state/linode.token（cora 600，Linodes＋Firewalls Read Only；熊哥 2026-10-15 建）
- 數據：/linode/instances/{id}/stats，Linode 每 5 分鐘一個點；取最近 30 分鐘（6 點）平均
- Linode API 沒有記憶體用量 → 用 swap 寫入量代替：平常幾乎是 0，持續有 swap 就是記憶體吃緊
- 門檻（10/16 依 6 台過去 24 小時校準：每核 CPU 平均 4–20%、p95 ≤ 30%；swap 平均 ≤ 0.1、偶發尖峰約 10）
    CPU（每核，30 分平均）≥ 70% 黃、≥ 90% 紅；swap（30 分平均，blocks/s）≥ 5 黃、≥ 20 紅；狀態不是 running 紅
    IO、網路各台常態差很多（vm05 平常 IO 就 160），只顯示不判燈
- cron（cora）：*/5 * * * * python3 /srv/dev/ops.ccnda.net/tools/linode_stats.py
"""
import json, os, sys, time, urllib.request, urllib.error

BASE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "state")
API = "https://api.linode.com/v4"
WINDOW = 6  # 最近 6 個 5 分鐘點 = 30 分鐘
CPU_WARN, CPU_BAD = 70.0, 90.0
SWAP_WARN, SWAP_BAD = 5.0, 20.0


def api(path, tok):
    req = urllib.request.Request(API + path, headers={"Authorization": "Bearer " + tok})
    return json.loads(urllib.request.urlopen(req, timeout=30).read())


def recent(series):
    """最近 WINDOW 個非空點的平均與最新值；沒有資料回 (None, None)"""
    v = [x for _, x in (series or []) if x is not None][-WINDOW:]
    return (round(sum(v) / len(v), 2), round(v[-1], 2)) if v else (None, None)


def judge(h):
    why = []
    if h["status"] != "running":
        why.append(("bad", f"狀態 {h['status']}"))
    if h["cpu_avg"] is not None:
        if h["cpu_avg"] >= CPU_BAD:
            why.append(("bad", f"CPU {h['cpu_avg']:.0f}%"))
        elif h["cpu_avg"] >= CPU_WARN:
            why.append(("warn", f"CPU {h['cpu_avg']:.0f}%"))
    if h["swap_avg"] is not None:
        if h["swap_avg"] >= SWAP_BAD:
            why.append(("bad", f"swap {h['swap_avg']:.1f}（記憶體吃緊）"))
        elif h["swap_avg"] >= SWAP_WARN:
            why.append(("warn", f"swap {h['swap_avg']:.1f}（記憶體偏緊）"))
    if h["status"] == "running" and h["cpu_avg"] is None:
        why.append(("warn", "暫時拿不到數據"))
    cls = "bad" if any(c == "bad" for c, _ in why) else ("warn" if why else "ok")
    return cls, [t for _, t in why]


def main():
    tok = open(os.path.join(BASE, "linode.token"), encoding="utf-8-sig").read().strip()
    hosts = []
    for i in api("/linode/instances?page_size=100", tok)["data"]:
        n = max(1, int(i["specs"]["vcpus"]))
        h = {"id": i["id"], "label": i["label"], "status": i["status"], "region": i["region"],
             "ip": (i.get("ipv4") or [""])[0], "vcpus": n, "memory_mb": i["specs"]["memory"],
             "cpu_avg": None, "cpu_now": None, "swap_avg": None, "io_avg": None,
             "net_in_kbps": None, "net_out_kbps": None, "stats_err": None}
        try:
            d = api(f"/linode/instances/{i['id']}/stats", tok)
            d = d.get("data", d)
            ca, cn = recent(d.get("cpu"))
            h["cpu_avg"] = round(ca / n, 1) if ca is not None else None  # Linode 的 CPU% 是各核加總
            h["cpu_now"] = round(cn / n, 1) if cn is not None else None
            h["swap_avg"] = recent((d.get("io") or {}).get("swap"))[0]
            h["io_avg"] = recent((d.get("io") or {}).get("io"))[0]
            nin, nout = recent((d.get("netv4") or {}).get("in"))[1], recent((d.get("netv4") or {}).get("out"))[1]
            h["net_in_kbps"] = round(nin / 1000, 1) if nin is not None else None
            h["net_out_kbps"] = round(nout / 1000, 1) if nout is not None else None
        except urllib.error.HTTPError as e:  # Linode 剛開機或維護時 stats 會暫時回 400
            h["stats_err"] = f"HTTP {e.code}"
        h["cls"], h["why"] = judge(h)
        hosts.append(h)
    hosts.sort(key=lambda h: h["label"])
    tmp = os.path.join(BASE, "linode.json.tmp")
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump({"at": int(time.time()), "hosts": hosts}, f, ensure_ascii=False)
    os.chmod(tmp, 0o644)
    os.replace(tmp, os.path.join(BASE, "linode.json"))
    print(time.strftime("%F %T"), " ".join(f"{h['label']}={h['cls']}" for h in hosts))


if __name__ == "__main__":
    try:
        main()
    except Exception as e:  # 不印權杖；cron 輸出只留錯誤類型與訊息
        print("linode_stats 失敗：", type(e).__name__, str(e)[:200], file=sys.stderr)
        sys.exit(1)
