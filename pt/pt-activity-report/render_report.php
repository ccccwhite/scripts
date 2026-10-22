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
// Merge only identical Info Hash values. Keep separate clients in details.
$groups=[];
foreach($records as $r){
    $hash=strtolower(trim(getv($r,'Hash')));
    $site=getv($r,'站点');
    $key=$hash!=='' ? $site.'|'.$hash : $site.'|'.getv($r,'下载器').'|'.getv($r,'种子名称');
    $groups[$key][]=$r;
}
$rows='';
foreach($groups as $items){
    $first=$items[0];
    $site=getv($first,'站点');
    $name=getv($first,'种子名称');
    $clients=array_values(array_unique(array_map(fn($r)=>getv($r,'下载器'),$items)));
    $hours=getv($first,'同站同Hash合计做种小时');
    $ratio=getv($first,'同站同Hash合计分享率估算');
    $hr=getv($first,'H&R本地估算');
    $activity='';
    $details='';
    foreach($items as $r){
        $dt=getv($r,'最近数据传输时间');
        if($dt!=='' && $dt!=='未知' && $dt>$activity)$activity=$dt;
        $details.='<section><b>'.esc(getv($r,'下载器')).'</b>'
            .'<div>做种：'.esc(getv($r,'本客户端做种小时')).' 小时 · 分享率：'.esc(getv($r,'本客户端分享率')).'</div>'
            .'<div>累计上传：'.esc(getv($r,'累计上传字节')).' 字节</div>'
            .'<div>最近活动：'.esc($dt).'</div>'
            .'<div>路径：'.esc(getv($r,'保存路径')).'</div>'
            .'<div>标签：'.esc(getv($r,'分类或标签')).'</div>'
            .'</section>';
    }
    $details.='<div>Hash：<code>'.esc(getv($first,'Hash')).'</code></div>';
    $detail='<details><summary>'.esc($name).'</summary><div class="details">'.$details.'</div></details>';
    $rows.='<tr><td>'.esc($site).'</td><td>'.esc(implode(' + ',$clients)).'</td><td class="name">'.$detail.'</td>'
        .'<td data-sort="'.esc($activity).'">'.esc($activity?:'未知').'</td>'
        .'<td data-sort="'.esc(is_numeric($hours)?$hours:-1).'">'.esc($hours).'</td>'
        .'<td data-sort="'.esc(is_numeric($ratio)?$ratio:-1).'">'.esc($ratio).'</td>'
        .'<td>'.esc($hr).'</td></tr>'."\n";
}
$taskCount=count($records);
$uniqueCount=count($groups);
$clientCounts=[];
foreach($records as $r){$c=getv($r,'下载器');$clientCounts[$c]=($clientCounts[$c]??0)+1;}
$clientSummary=implode(' · ',array_map(fn($c,$n)=>$c.': '.$n,array_keys($clientCounts),array_values($clientCounts)));
$updated=esc((new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s'));
$count=$uniqueCount;
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
<header><div><h1>PT 种子活跃度</h1><p>更新：__UPDATED__ · 共 __COUNT__ 个不同种子 · __TASKCOUNT__ 条下载器任务 · 显示 <span id="visible">__COUNT__</span> 条<br>__CLIENTSUMMARY__</p></div>
<input id="search" type="search" placeholder="搜索站点、种子名、下载器或 H&R 状态…"></header>
<div class="wrapper"><table id="report"><thead><tr><th>站点</th><th>下载器</th><th>种子名称 / 详情</th><th>最近活动</th><th>合计做种（小时）</th><th>合计分享率</th><th>H&R 本地评估</th></tr></thead>
<tbody>__ROWS__</tbody></table></div>
<p class="note">相同站点与 Info Hash 的任务合并成一行；最近活动不一定代表实际上传。合计做种时间可能包含两个客户端的重叠时间。合计分享率是本地估算，H&R 不代表站点判定。点击表头排序，点击种子名称展开详情。</p>
<script>
const table=document.getElementById('report'), rows=[...table.tBodies[0].rows],search=document.getElementById('search');
let sortColumn=3,sortAsc=false;
function redraw(){const q=search.value.trim().toLocaleLowerCase();const shown=rows.filter(r=>r.textContent.toLocaleLowerCase().includes(q));shown.sort((a,b)=>{const ca=a.cells[sortColumn],cb=b.cells[sortColumn];const va=ca.dataset.sort??ca.textContent.trim(),vb=cb.dataset.sort??cb.textContent.trim();const na=Number(va),nb=Number(vb);const result=va!==''&&vb!==''&&!Number.isNaN(na)&&!Number.isNaN(nb)?na-nb:va.localeCompare(vb,'zh-CN');return sortAsc?result:-result;});table.tBodies[0].replaceChildren(...shown);document.getElementById('visible').textContent=shown.length;}
[...table.tHead.rows[0].cells].forEach((th,i)=>th.addEventListener('click',()=>{sortAsc=sortColumn===i?!sortAsc:true;sortColumn=i;redraw()}));search.addEventListener('input',redraw);redraw();
</script></body></html>
HTML;
$page=str_replace(['__UPDATED__','__COUNT__','__TASKCOUNT__','__CLIENTSUMMARY__','__ROWS__'],[$updated,(string)$count,(string)$taskCount,esc($clientSummary),$rows],$page);
$tmp=$htmlPath.'.tmp';
if(file_put_contents($tmp,$page)===false){fwrite(STDERR,"Cannot write report HTML\n");exit(1);}
chmod($tmp,0600);
if(!rename($tmp,$htmlPath)){fwrite(STDERR,"Cannot replace report HTML\n");exit(1);}
echo "HTML 报表: $htmlPath ($count 个不同种子，$taskCount 条下载器任务)\n";