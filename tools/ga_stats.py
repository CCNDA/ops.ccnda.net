#!/usr/bin/env python3
"""戰情室「網站流量」卡：每 15 分鐘讀 GA4 與 Search Console，寫 state/ga.json（頁面只讀這個檔）。
熊哥 2026-10-11：「GA 之後戰情室開發會用到」（Asana 1219379138644448）。

- 權限：服務帳號代入 cora@ccnda.org，只申請 analytics.readonly＋webmasters.readonly
  （熊哥 2026-10-16 TG 准；只有這支用，不加進 get_sa_token 的預設 SCOPES）
- GA4：cora 看得到的每個資源，即時（最近 30 分鐘）使用者＋今天／昨天／過去 7 天／過去 30 天的使用者與瀏覽數
- Search Console：用網址主機對到每個 GA 網站（網址資源或 sc-domain: 網域資源），最近 7 天（資料約晚 2 天，取 9～3 天前）
  點擊、曝光、平均排名；對不到的記「未授權」（熊哥 10/16：每個網站都要有、放在流量表一起）
- 某個資源讀失敗只記在那一列（err），不影響其他列
- cron（cora）：3-59/15 * * * * cd $HOME/.config/gws && TZ=Asia/Taipei python3 /srv/dev/ops.ccnda.net/tools/ga_stats.py
"""
import datetime as dt, json, os, sys, time, urllib.parse, urllib.request, urllib.error

sys.path.insert(0, os.path.expanduser("~/.config/gws"))
import get_sa_token  # noqa: E402

# 不放上卡片的 GA 資源（用編號，不用名稱比對）：
# properties/311712282 熊族大雜燴（jbear.net）——熊哥 10/16：「不用上，這個網站幾乎關閉指內部使用」
# （只影響流量卡；jbear.net 仍在 Cloudflare 資安監控名單）
EXCLUDE = {"properties/311712282"}
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "state", "ga.json")
get_sa_token.SCOPES = "https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly"


def call(url, tok, body=None):
    req = urllib.request.Request(url, headers={"Authorization": "Bearer " + tok, "Content-Type": "application/json"},
                                 data=json.dumps(body).encode() if body is not None else None,
                                 method="POST" if body is not None else "GET")
    return json.loads(urllib.request.urlopen(req, timeout=30).read())


def ga_site(pid, tok):
    """回傳 {'uri', 'realtime', 'today', 'yesterday', 'week'}；各值是 {'users','views'}"""
    base = f"https://analyticsdata.googleapis.com/v1beta/{pid}"
    out = {}
    streams = call(f"https://analyticsadmin.googleapis.com/v1beta/{pid}/dataStreams", tok).get("dataStreams", [])
    out["uri"] = next((s.get("webStreamData", {}).get("defaultUri") for s in streams if s.get("webStreamData")), None)
    rt = call(base + ":runRealtimeReport", tok, {"metrics": [{"name": "activeUsers"}]})
    out["realtime"] = int((rt.get("rows") or [{"metricValues": [{"value": 0}]}])[0]["metricValues"][0]["value"])
    rep = call(base + ":runReport", tok, {
        "dateRanges": [{"startDate": "today", "endDate": "today", "name": "today"},
                       {"startDate": "yesterday", "endDate": "yesterday", "name": "yesterday"},
                       {"startDate": "7daysAgo", "endDate": "yesterday", "name": "week"},
                       {"startDate": "30daysAgo", "endDate": "yesterday", "name": "month"}],
        "metrics": [{"name": "activeUsers"}, {"name": "screenPageViews"}]})
    for k in ("today", "yesterday", "week", "month"):
        out[k] = {"users": None, "views": None}   # GA 還沒處理的日期不會有資料列；顯示「—」，不要當成 0
    for r in rep.get("rows", []):
        k = r["dimensionValues"][0]["value"]
        out[k] = {"users": int(r["metricValues"][0]["value"]), "views": int(r["metricValues"][1]["value"])}
    return out


def sc_site(url, tok):
    end = dt.date.today() - dt.timedelta(days=3)
    start = end - dt.timedelta(days=6)
    r = call(f"https://www.googleapis.com/webmasters/v3/sites/{urllib.parse.quote(url, safe='')}/searchAnalytics/query",
             tok, {"startDate": start.isoformat(), "endDate": end.isoformat()})
    row = (r.get("rows") or [{}])[0]
    return {"site": url, "from": start.isoformat(), "to": end.isoformat(),
            "clicks": int(row.get("clicks", 0)), "impressions": int(row.get("impressions", 0)),
            "ctr": round(row.get("ctr", 0) * 100, 1), "position": round(row.get("position", 0), 1)}


def host_of(url):
    """https://www.taiwanbible.com/ → taiwanbible.com；sc-domain:zh.church → zh.church"""
    u = (url or "").lower().replace("sc-domain:", "")
    u = urllib.parse.urlparse(u if "://" in u else "https://" + u).hostname or ""
    return u[4:] if u.startswith("www.") else u


def sc_for(uri, sc_sites):
    """GA 網站網址 → 對應的 Search Console 資源：先找同主機的網址資源，再找涵蓋它的網域資源（sc-domain:）"""
    h = host_of(uri)
    if not h:
        return None
    exact = [s for s in sc_sites if not s.startswith("sc-domain:") and host_of(s) == h]
    if exact:
        return exact[0]
    dom = [s for s in sc_sites if s.startswith("sc-domain:") and (h == host_of(s) or h.endswith("." + host_of(s)))]
    return dom[0] if dom else None


def main():
    tok = get_sa_token.get_access_token()
    acc = call("https://analyticsadmin.googleapis.com/v1beta/accountSummaries", tok)
    sites = []
    for a in acc.get("accountSummaries", []):
        for p in a.get("propertySummaries", []):
            if p["property"] in EXCLUDE:
                continue
            row = {"property": p["property"], "name": p["displayName"].replace(" - GA4", "").replace(" - GA 4", "").strip()}
            try:
                row.update(ga_site(p["property"], tok))
            except urllib.error.HTTPError as e:
                row["err"] = f"HTTP {e.code}"
            sites.append(row)
    sites.sort(key=lambda s: -((s.get("week") or {}).get("users") or 0))
    # Search Console：cora 看得到的資源對到每個網站；對不到＝還沒授權給 cora（熊哥要在 SC 加使用者）
    sc_sites = [s["siteUrl"] for s in call("https://www.googleapis.com/webmasters/v3/sites", tok).get("siteEntry", [])
                if s.get("permissionLevel") != "siteUnverifiedUser"]
    sc = []
    for row in sites:
        hit = sc_for(row.get("uri"), sc_sites)
        if not hit:
            row["search"] = {"err": "未授權"}
            continue
        try:
            row["search"] = sc_site(hit, tok)
        except urllib.error.HTTPError as e:
            row["search"] = {"site": hit, "err": f"HTTP {e.code}"}
        sc.append(row["search"])
    tmp = OUT + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump({"at": int(time.time()), "sites": sites, "search": sc}, f, ensure_ascii=False)
    os.chmod(tmp, 0o644)
    os.replace(tmp, OUT)
    print(time.strftime("%F %T"), f"GA {len(sites)} 個（失敗 {sum(1 for s in sites if 'err' in s)}）・SC 對到 {len(sc)} 個（Search Console 資源 {len(sc_sites)} 個）")


if __name__ == "__main__":
    try:
        main()
    except Exception as e:  # 不印權杖
        print("ga_stats 失敗：", type(e).__name__, str(e)[:200], file=sys.stderr)
        sys.exit(1)
