<?php
declare(strict_types=1);
$base = __DIR__;
$csv = $base . '/report.csv';
$htmlPath = $base . '/report.html';
if (!is_readable($csv)) { fwrite(STDERR, "CSV source unavailable: $csv\n"); exit(1); }
$stream = fopen($csv, 'rb');
if (!$stream) { fwrite(STDERR, "Cannot open report CSV\n"); exit(1); }
$headers = fgetcsv($stream);
if (!is_array($headers)) { fclose($stream); exit(1); }
$headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
$records = [];
while (($values = fgetcsv($stream)) !== false) {
    if (count($values) !== count($headers)) continue;
    $records[] = array_combine($headers, $values);
}
fclose($stream);
function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function getv(array $r, string $key): string { return (string)($r[$key] ?? ''); }
$rows = '';
foreach ($records as $r) {
    $name=getv($r,'种子名称');
    $site=getv($r,'站点');
    $client=getv($r,'下载器');
    $inactive=getv($r,'未观察到上传天数');
    $hours=getv($r,'同站同Hash合计做种小时');
    $ratio=getv($r,'同站同Hash合计分享率估算');
    $hr=getv($r,'H&R本地估算');
    $nameHtml=esc($name);
    $detail = '<details><summary title="展开详情">'.$nameHtml.'</summary><div class="details">'
        .'<div><b>Hash：</b><code>'.esc(getv($r,'Hash')).'</code></div>'
        .'<div><b>保存路径：</b>'.esc(getv($r,'保存路径')).'</div>'
        .'<div><b>标签：</b>'.esc(getv($r,'分类或标签')).'</div>'
        .'<div><b>当前客户端做种：</b>'.esc(getv($r,'本客户端做种小时')).' 小时</div>'
        .'<div><b>当前客户端分享率：</b>'.esc(getv($r,'本客户端分享率')).'</div>'
        .'<div><b>累计上传字节：</b>'.esc(getv($r,'累计上传字节')).'</div>'
        .'<div><b>最近传输活动：</b>'.esc(getv($r,'最近数据传输时间')).'</div>'
        .'<div><b>状态：</b>'.esc(getv($r,'状态说明')).'</div>'
        .'</div></details>';
    $rows.='<tr><td>'.esc($site).'</td><td>'.esc($client).'</td><td class="name">'.$detail.'</td>'
        .'<td data-sort="'.esc(is_numeric($inactive)?$inactive:-1).'">'.esc($inactive).'</td>'
        .'<td data-sort="'.esc(is_numeric($hours)?$hours:-1).'">'.esc($hours).'</td>'
        .'<td data-sort="'.esc(is_numeric($ratio)?$ratio:-1).'">'.esc($ratio).'</td>'
        .'<td>'.esc($hr).'</td></tr>' . "\n";
}
$updated=esc((new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s'));
$count=count($records);
$page=<<<'HTML'
<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>PT 种子活跃度</title><style>
:root{font-family:system-ui,-apple-system,"Microsoft YaHei",sans-serif;color-scheme:light dark}body{max-width:1600px;margin:25px auto;padding:0 18px;font-size:14px}
header{display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap}h1{font-size:23px;margin:0 0 8px}p{color:gray;margin:5px 0 15px}
input{padding:10px 12px;width:min(420px,90%);border:1px solid #8886;border-radius:8px;font:inherit}
.wrapper{overflow-x:auto;border:1px solid #8883;border-radius:10px;margin-top:15px}table{width:100%;border-collapse:collapse;min-width:970px}
th,td{padding:12px 10px;border-bottom:1px solid #8883;text-align:left;vertical-align:top}th{white-space:nowrap;cursor:pointer;user-select:none;background:#8881}th:hover{background:#8882}
tr:last-child td{border-bottom:0}tbody tr:hover{background:#8881}.name{min-width:260px;max-width:570px}summary{cursor:pointer;overflow-wrap:anywhere;font-weight:500}
.details{color:gray;padding:10px 0 2px;font-size:12px;line-height:1.9;overflow-wrap:anywhere}.details code{font-size:11px;white-space:normal}
.note{font-size:12px;margin-top:16px}#visible{font-variant-numeric:tabular-nums}
</style></head><body>
<header><div><h1>PT 种子活跃度</h1><p>更新：__UPDATED__ · 共 __COUNT__ 条下载器任务 · 显示 <span id="visible">__COUNT__</span> 条</p></div>
<input id="search" type="search" placeholder="搜索站点、种子名、下载器或 H&R 状态…"></header>
<div class="wrapper"><table id="report"><thead><tr><th>站点</th><th>下载器</th><th>种子名称 / 详情</th><th>未上传（天）</th><th>合计做种（小时）</th><th>合计分享率</th><th>H&R 本地评估</th></tr></thead>
<tbody>__ROWS__</tbody></table></div>
<p class="note">未上传时长从首次采样后逐步建立；“未知”不代表 0 天。同站同 Hash 合计值是本地客户端统计，不等于 PT 站点最终 H&R 判定。点击表头排序，点击种子名称展开详情。</p>
<script>
const table=document.getElementById('report'), rows=[...table.tBodies[0].rows],search=document.getElementById('search');
let sortColumn=3,sortAsc=false;
function redraw(){const q=search.value.trim().toLocaleLowerCase();const shown=rows.filter(r=>r.textContent.toLocaleLowerCase().includes(q));shown.sort((a,b)=>{const ca=a.cells[sortColumn],cb=b.cells[sortColumn];const va=ca.dataset.sort??ca.textContent.trim(),vb=cb.dataset.sort??cb.textContent.trim();const na=Number(va),nb=Number(vb);const result=va!==''&&vb!==''&&!Number.isNaN(na)&&!Number.isNaN(nb)?na-nb:va.localeCompare(vb,'zh-CN');return sortAsc?result:-result;});table.tBodies[0].replaceChildren(...shown);document.getElementById('visible').textContent=shown.length;}
[...table.tHead.rows[0].cells].forEach((th,i)=>th.addEventListener('click',()=>{sortAsc=sortColumn===i?!sortAsc:true;sortColumn=i;redraw()}));search.addEventListener('input',redraw);redraw();
</script></body></html>
HTML;
$page=str_replace(['__UPDATED__','__COUNT__','__ROWS__'],[$updated,(string)$count,$rows],$page);
$tmp=$htmlPath.'.tmp';
if(file_put_contents($tmp,$page)===false){fwrite(STDERR,"Cannot write report HTML\n");exit(1);}
chmod($tmp,0600);
if(!rename($tmp,$htmlPath)){fwrite(STDERR,"Cannot replace report HTML\n");exit(1);}
echo "HTML 报表: $htmlPath ($count 条任务)\n";