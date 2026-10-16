#!/usr/bin/env python3
"""戰情室資安事件：Cloudflare 被擋／被挑戰的請求暴增偵測（熊哥 2026-10-16 裁示；Asana 1219443887250576）。

每 5 分鐘：查 CCNDA 14 個 zone 約 5～10 分鐘前那一段 5 分鐘被擋的請求數（httpRequestsAdaptiveGroups 依 securityAction，
Free 方案沒有 firewallEventsAdaptiveGroups），跟該 zone 自己的門檻比，寫 state/cf.json；推播由 push_watch.php 依 cls 轉換推。
- 門檻＝max(3 × 過去 7 天每 5 分鐘被擋數的 p99.9, 300)，每天重算一次（state/cf_thr.json）。
  10/16 校準：過去 7 天 14 個 zone 只會推 3 次（taiwanbible.com 8,197、biblesearch.com.tw 6,036、oursweb.org 4,905）。
- 暴增：超過門檻就 cls=bad；回落要連續 2 段都低於門檻才回 ok（避免一段高一段低來回推）。
- 暴增時補前 3 名來源國家與規則（firewallEventsAdaptive 原始事件，最多取 1000 筆）。
- 權杖：~/.config/cloudflare/token（唯讀 Zone Analytics Read，熊哥 10/16 建），不印出。
- cron（cora）：1-59/5 * * * * python3 /srv/dev/ops.ccnda.net/tools/cf_watch.py >> /srv/dev/ops.ccnda.net/state/cf_watch.log 2>&1
"""
import datetime as dt, json, os, sys, time, urllib.request

ZONES = ["ccnda.org", "ccnda.net", "zh.church", "taiwanbible.com", "taiwanbible.com.tw", "biblesearch.com.tw",
         "biblesearch.tw", "oursweb.com", "oursweb.net", "oursweb.org", "loveuno.com", "ps194.net", "ps194.org", "jbear.net"]
NORMAL = {"unknown", "allow", "skip", "log", ""}
STATE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "state")
OUT, THR, ST = (os.path.join(STATE, n) for n in ("cf.json", "cf_thr.json", "cf_state.json"))
MULT, FLOOR, RECOVER_SLOTS = 3, 300, 2
GQL = "https://api.cloudflare.com/client/v4/graphql"


def load(p, d):
    try:
        return json.load(open(p, encoding="utf-8"))
    except (OSError, ValueError):
        return d


def save(p, obj, mode=0o644):
    tmp = p + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(obj, f, ensure_ascii=False)
    os.chmod(tmp, mode)
    os.replace(tmp, p)


class CF:
    def __init__(self):
        tok = open(os.path.expanduser("~/.config/cloudflare/token")).read().strip()
        self.h = {"Authorization": "Bearer " + tok, "Content-Type": "application/json"}

    def call(self, url, body=None):
        req = urllib.request.Request(url, headers=self.h, data=json.dumps(body).encode() if body else None)
        return json.loads(urllib.request.urlopen(req, timeout=60).read())

    def gql(self, q, v):
        r = self.call(GQL, {"query": q, "variables": v})
        if r.get("errors"):
            raise RuntimeError(r["errors"][0].get("message", "")[:120])
        return r["data"]["viewer"]["zones"][0]

    def zone_ids(self):
        ids, page = {}, 1
        while True:
            r = self.call(f"https://api.cloudflare.com/client/v4/zones?per_page=50&page={page}")
            ids.update({z["name"]: z["id"] for z in r["result"]})
            if page >= r["result_info"]["total_pages"]:
                return ids
            page += 1

    def blocked_by_slot(self, zid, s, u):
        q = """query($z:String!,$s:Time!,$u:Time!){viewer{zones(filter:{zoneTag:$z}){
          httpRequestsAdaptiveGroups(limit:10000, filter:{datetime_geq:$s, datetime_lt:$u, securityAction_neq:"unknown"}){
            count dimensions{ datetimeFiveMinutes securityAction } } } } }"""
        out = {}
        for g in self.gql(q, {"z": zid, "s": iso(s), "u": iso(u)})["httpRequestsAdaptiveGroups"]:
            if g["dimensions"]["securityAction"] not in NORMAL:
                k = g["dimensions"]["datetimeFiveMinutes"]
                out[k] = out.get(k, 0) + g["count"]
        return out

    def top_sources(self, zid, s, u):
        q = """query($z:String!,$s:Time!,$u:Time!){viewer{zones(filter:{zoneTag:$z}){
          firewallEventsAdaptive(limit:1000, filter:{datetime_geq:$s, datetime_lt:$u}){ clientCountryName source ruleId action } } } }"""
        rows = self.gql(q, {"z": zid, "s": iso(s), "u": iso(u)})["firewallEventsAdaptive"]
        cnt = lambda key: [k for k, _ in sorted(count_by(rows, key).items(), key=lambda x: -x[1])[:3]]
        return {"countries": cnt("clientCountryName"), "rules": cnt("source"), "sampled": len(rows)}


def iso(t):
    return t.isoformat().replace("+00:00", "Z")


def count_by(rows, key):
    d = {}
    for r in rows:
        d[r.get(key) or "?"] = d.get(r.get(key) or "?", 0) + 1
    return d


def thresholds(cf, ids, now):
    cache = load(THR, {})
    if cache and time.time() - cache.get("at", 0) < 86400 and all(z in cache.get("zones", {}) for z in ZONES):
        return cache["zones"]
    zones = {}
    for name in ZONES:
        slots = {}
        for d in range(7):  # 一天一查，避免單次超過 10000 組
            u = now - dt.timedelta(days=d)
            slots.update(cf.blocked_by_slot(ids[name], u - dt.timedelta(days=1), u))
        srt = sorted(list(slots.values()) + [0] * (7 * 288 - len(slots)))
        p999 = srt[min(len(srt) - 1, int(len(srt) * 0.999))]
        zones[name] = {"thr": max(MULT * p999, FLOOR), "p999": p999}
    save(THR, {"at": int(time.time()), "zones": zones})
    return zones


def main():
    cf = CF()
    ids = cf.zone_ids()
    now = dt.datetime.now(dt.timezone.utc).replace(second=0, microsecond=0)
    # 查「再往前一段」（約 5～10 分鐘前）：Cloudflare 分析資料有幾分鐘延遲，剛結束那段的數字常還沒算完
    end = now - dt.timedelta(minutes=now.minute % 5) - dt.timedelta(minutes=5)
    start = end - dt.timedelta(minutes=5)
    thr = thresholds(cf, ids, end)
    st = load(ST, {})
    rows = []
    for name in ZONES:
        z = {"name": name, "thr": thr[name]["thr"]}
        try:
            z["count"] = sum(cf.blocked_by_slot(ids[name], start, end).values())
        except Exception as e:  # 單一 zone 失敗不影響其他
            z["err"] = f"{type(e).__name__}: {str(e)[:80]}"
            rows.append(z); continue
        s = st.get(name, {"spike": False, "below": 0})
        if z["count"] >= z["thr"]:
            s = {"spike": True, "below": 0}
        elif s["spike"]:
            s["below"] += 1
            if s["below"] >= RECOVER_SLOTS:
                s = {"spike": False, "below": 0}
        st[name] = s
        z["cls"] = "bad" if s["spike"] else "ok"
        if s["spike"]:
            try:
                z.update(cf.top_sources(ids[name], start, end))
            except Exception:
                pass
        rows.append(z)
    save(ST, st)
    save(OUT, {"at": int(time.time()), "slot": iso(start), "zones": rows})
    print(time.strftime("%F %T"), iso(start), " ".join(f"{r['name']}={r.get('count', 'ERR')}/{r['thr']}" for r in rows if r.get("cls") == "bad" or "err" in r) or "全部正常")


if __name__ == "__main__":
    try:
        main()
    except Exception as e:  # 不印權杖
        print("cf_watch 失敗：", type(e).__name__, str(e)[:200], file=sys.stderr)
        sys.exit(1)
