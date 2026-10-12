<?php
declare(strict_types=1);

/**
 * 小芯開發中專案狀態（戰情室卡片；Asana 1219411554379151）。
 * 熊哥 2026-10-12：「我需要你能在戰情室中，回報你目前開發專案的狀態」。
 * 資料：tools/projects_status.py 每 10 分鐘把 state/projects.json（小芯手動維護）加上
 *       正式站版本、測試站分支，寫成 state/projects_view.json。
 */

const PROJECTS_FILE = __DIR__ . '/../state/projects_view.json';

// 階段：鍵 => [顯示文字, 樣式]。「卡在誰」另外寫在每一項的 waiting。
const PROJECT_STAGES = [
    'dev'     => ['開發中', 'st-dev'],
    'queued'  => ['排隊中', 'st-queued'],
    'retest'  => ['小布複測中', 'st-retest'],
    'confirm' => ['等熊叔叔確認', 'st-confirm'],
    'deploy'  => ['等部署', 'st-deploy'],
    'plan'    => ['規劃中', 'st-plan'],
    'blocked' => ['卡住', 'st-blocked'],
    'done'    => ['已上線', 'st-done'],
];

/** @return array{0: ?array, 1: string} */
function projects_data(): array
{
    if (!is_file(PROJECTS_FILE)) {
        return [null, '還沒有專案狀態資料。'];
    }
    $d = json_decode((string) file_get_contents(PROJECTS_FILE), true);
    if (!is_array($d) || !isset($d['projects']) || !is_array($d['projects'])) {
        return [null, '專案狀態資料格式不對。'];
    }
    $age = time() - (int) filemtime(PROJECTS_FILE);
    return [$d, $age > 1800 ? '專案狀態超過 30 分鐘沒有更新（排程可能停了）。' : ''];
}

/** 只放 http(s) 連結，其他一律不輸出 */
function project_link(?string $url, string $text): string
{
    if ($url === null || !preg_match('#^https?://#i', $url)) {
        return '';
    }
    return '<a href="' . h($url) . '" target="_blank" rel="noopener">' . h($text) . '</a>';
}
