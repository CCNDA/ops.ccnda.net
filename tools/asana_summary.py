#!/usr/bin/env python3
"""戰情室任務卡：每小時從 Asana 統計，寫 state/asana_summary.json（頁面只讀這個檔，不碰權杖）。
熊哥 2026-10-11：「我要類似這樣 任務要讀 asana」（Asana 1219390021775751）。

- 權杖：state/asana.pat（cora 600，只有這支程式讀；www-data 讀不到）
  ★ 用熊哥（黑熊）的個人權杖：熊哥 2026-10-11 21:09「要算整個工作區，但是要我的權限才可以」「我給你權杖」。
    宓芯的帳號只看得到她加入的專案。Asana 權杖沒有唯讀選項，這支程式只做 GET；熊哥可隨時在 Asana 撤銷。
- 範圍：CCNDA 工作區、熊哥帳號看得到的任務（含子任務）
- 時間以台北時間計算「今天」「本月」
- 排除：【範本來源】月重點執行任務（任務本來就不帶日期）與已封存專案的任務；子任務看母任務屬於哪裡
  熊哥 2026-10-15 19:2x TG 按鈕裁示（媽媽信轉達；Asana 1219533857034205）
- cron（cora）：7 * * * * python3 /srv/dev/ops.ccnda.net/tools/asana_summary.py
"""
import datetime as dt, json, os, sys, time, urllib.parse, urllib.request, urllib.error

BASE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "state")
WS = "368265232362384"
TZ = dt.timezone(dt.timedelta(hours=8))
FIELDS = "name,due_on,completed,completed_at,assignee.name,permalink_url,created_at,memberships.project.gid,parent.gid"
TEMPLATE_PROJECT = "1219045142697771"  # 【範本來源】月重點執行任務

def api(path, params):
    tok = open(os.path.join(BASE, "asana.pat"), encoding="utf-8-sig").read().strip()
    url = "https://app.asana.com/api/1.0" + path + "?" + urllib.parse.urlencode(params)
    for attempt in range(3):
        try:
            req = urllib.request.Request(url, headers={"Authorization": "Bearer " + tok})
            return json.loads(urllib.request.urlopen(req, timeout=30).read())["data"]
        except urllib.error.HTTPError as e:
            if e.code == 429 and attempt < 2:
                time.sleep(int(e.headers.get("Retry-After", "10")))
                continue
            raise

def search(**filters):
    """搜尋 API 一頁最多 100 筆、沒有 offset：用 created_at 由新到舊往前翻。
    ★ 下一頁的 created_at.before 要用「最後一筆的時間 + 1 秒」再用 gid 去重：
      直接用最後一筆的時間，同一秒建立的票卡在分頁邊界就會被跳過
      （2026-10-11 第一版因此逾期少算 2、當月到期少算 7）。"""
    out, seen, before = [], set(), None
    while True:
        p = dict(filters, opt_fields=FIELDS, sort_by="created_at", sort_ascending="false", limit=100)
        if before:
            p["created_at.before"] = before
        page = api(f"/workspaces/{WS}/tasks/search", p)
        new = [t for t in page if t["gid"] not in seen]
        seen |= {t["gid"] for t in new}
        out += new
        if len(page) < 100 or not new:
            return out
        last = dt.datetime.fromisoformat(page[-1]["created_at"].replace("Z", "+00:00"))
        before = (last + dt.timedelta(seconds=1)).strftime("%Y-%m-%dT%H:%M:%S.000Z")
        if before == p.get("created_at.before"):  # 同一秒超過 100 張才會發生；避免無限迴圈
            return out

def excluded_projects():
    """範本專案＋所有已封存專案。"""
    out, offset = {TEMPLATE_PROJECT}, None
    while True:
        p = {"workspace": WS, "archived": "true", "limit": 100, "opt_fields": "gid"}
        if offset:
            p["offset"] = offset
        tok = open(os.path.join(BASE, "asana.pat"), encoding="utf-8-sig").read().strip()
        url = "https://app.asana.com/api/1.0/projects?" + urllib.parse.urlencode(p)
        r = json.loads(urllib.request.urlopen(urllib.request.Request(
            url, headers={"Authorization": "Bearer " + tok}), timeout=30).read())
        out |= {x["gid"] for x in r["data"]}
        offset = (r.get("next_page") or {}).get("offset")
        if not offset:
            return out

def make_is_excluded(excl):
    """任務本身在排除專案裡，或任何一層母任務在裡面，就排除。
    子任務本身沒有 memberships，所以要往上找母任務（結果快取，同一個母任務只查一次）。"""
    cache = {}
    def check(t, depth=0):
        if any((m.get("project") or {}).get("gid") in excl for m in t.get("memberships") or []):
            return True
        parent = (t.get("parent") or {}).get("gid")
        if not parent or depth > 10:
            return False
        if parent not in cache:
            cache[parent] = check(api(f"/tasks/{parent}", {"opt_fields": "memberships.project.gid,parent.gid"}), depth + 1)
        return cache[parent]
    return check

def brief(t):
    return {"name": t["name"], "due": t.get("due_on"), "who": (t.get("assignee") or {}).get("name"),
            "url": t.get("permalink_url"), "done_at": t.get("completed_at")}

def main():
    now = dt.datetime.now(TZ)
    today = now.date()
    m0 = today.replace(day=1)
    m1 = (m0 + dt.timedelta(days=32)).replace(day=1)
    d = lambda x: x.isoformat()

    is_excluded = make_is_excluded(excluded_projects())
    open_tasks = [t for t in search(completed="false") if not is_excluded(t)]
    done_month = [t for t in search(completed="true", **{"completed_on.after": d(m0 - dt.timedelta(days=1))})
                  if not is_excluded(t)]

    buckets = {
        "today_due":  [t for t in open_tasks if t.get("due_on") == d(today)],
        "today_done": [t for t in done_month if (t.get("completed_at") or "")[:10] and
                       dt.datetime.fromisoformat(t["completed_at"].replace("Z", "+00:00")).astimezone(TZ).date() == today],
        "month_due":  [t for t in open_tasks if t.get("due_on") and d(m0) <= t["due_on"] < d(m1)],
        "month_done": done_month,
        "overdue":    [t for t in open_tasks if t.get("due_on") and t["due_on"] < d(today)],
    }
    for k in ("today_due", "month_due", "overdue"):
        buckets[k].sort(key=lambda t: (t.get("due_on") or "", t["name"]))
    buckets["today_done"].sort(key=lambda t: t.get("completed_at") or "", reverse=True)
    buckets["month_done"].sort(key=lambda t: t.get("completed_at") or "", reverse=True)

    data = {
        "at": int(time.time()),
        "today": d(today),
        "counts": {k: len(v) for k, v in buckets.items()},
        "open_total": len(open_tasks),
        "open_no_due": sum(1 for t in open_tasks if not t.get("due_on")),
        "lists": {k: [brief(t) for t in v] for k, v in buckets.items()},
    }
    tmp = os.path.join(BASE, "asana_summary.json.tmp")
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False)
    os.chmod(tmp, 0o644)
    os.replace(tmp, os.path.join(BASE, "asana_summary.json"))
    print(now.strftime("%F %T"), data["counts"], "未完成", data["open_total"], "無到期日", data["open_no_due"])

if __name__ == "__main__":
    try:
        main()
    except Exception as e:  # 不印權杖；cron 的輸出只留錯誤類型與訊息
        print("asana_summary 失敗：", type(e).__name__, str(e)[:200], file=sys.stderr)
        sys.exit(1)
