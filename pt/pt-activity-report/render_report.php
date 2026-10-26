<?php
declare(strict_types=1);
$base = __DIR__;
$cfg=json_decode((string)file_get_contents($argv[1]??$base.'/config.json'),true,512,JSON_THROW_ON_ERROR);
$csv=$cfg['csv_file']??$base.'/report.csv';
$htmlPath=$cfg['html_file']??dirname($csv).'/report.html';
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
    $hours=getv($first,'同站同Hash合计做种小时');
    $ratio=getv($first,'同站同Hash合计分享率估算');
    $hr=getv($first,'H&R本地估算');
    $activity=''; $completed=''; $uploadTotal=0;
    foreach($items as $r){
        $dt=getv($r,'最近数据传输时间');
        if($dt!=='' && $dt!=='未知' && $dt>$activity)$activity=$dt;
        $done=getv($r,'下载完成时间');
        if($done!==''&&$done!=='未知'&&($completed===''||$done<$completed))$completed=$done;
        $uploadTotal+=(float)getv($r,'累计上传字节');
    }
    $nameHtml='<details><summary>'.esc($name).'</summary><div class="details">Hash：<code>'.esc(getv($first,'Hash')).'</code></div></details>';
    $rows.='<tbody class="torrent-group"><tr class="total"><td>'.esc($site).'</td><td><b>合计</b></td>'
        .'<td class="name">'.$nameHtml.'</td><td data-sort="'.esc($activity).'">'.esc($activity?:'未知').'</td>'
        .'<td data-sort="'.esc($completed).'">'.esc($completed?:'未知').'</td>'
        .'<td data-sort="'.esc(is_numeric($hours)?$hours:-1).'">'.esc($hours).'</td>'
        .'<td data-sort="'.esc(is_numeric($ratio)?$ratio:-1).'">'.esc($ratio).'</td>'
        .'<td data-sort="'.esc($uploadTotal).'">'.esc(round($uploadTotal/1073741824,2)).' GiB</td><td>'.esc($hr).'</td></tr>';
    foreach($items as $r){
        $client=getv($r,'下载器');
        $ch=getv($r,'本客户端做种小时');
        $cr=getv($r,'本客户端分享率');
        $dt=getv($r,'最近数据传输时间');
        $uploaded=(float)getv($r,'累计上传字节');
        $detail='<details><summary>路径 / 标签</summary><div class="details">路径：'.esc(getv($r,'保存路径'))
            .'<div>标签：'.esc(getv($r,'分类或标签')).'</div>'
            .'<div>状态：'.esc(getv($r,'状态说明')).'</div></div></details>';
        $rows.='<tr class="client"><td></td><td>'.esc($client).'</td><td class="name">'.$detail.'</td>'
            .'<td>'.esc($dt?:'未知').'</td><td>'.esc(getv($r,'下载完成时间')?:'未知').'</td><td>'.esc($ch).'</td><td>'.esc($cr).'</td>'
            .'<td>'.esc(round($uploaded/1073741824,2)).' GiB</td><td></td></tr>';
    }
    $rows.='</tbody>'."\n";
}
$taskCount=count($records);
$uniqueCount=count($groups);
$clientCounts=[];
foreach($records as $r){$c=getv($r,'下载器');$clientCounts[$c]=($clientCounts[$c]??0)+1;}
$clientSummary=implode(' · ',array_map(fn($c,$n)=>$c.': '.$n,array_keys($clientCounts),array_values($clientCounts)));
$updated=esc((new DateTimeImmutable('now', new DateTimeZone($cfg['timezone']??'Asia/Shanghai')))->format('Y-m-d H:i:s'));
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
tbody.torrent-group{border-bottom:2px solid #8885}tbody tr:hover{background:#8881}tr.total{background:#8881;font-weight:600}tr.client td{font-size:13px;color:gray}tr.client td:nth-child(2){padding-left:22px}.name{min-width:260px;max-width:570px}summary{cursor:pointer;overflow-wrap:anywhere;font-weight:500}
.details{color:gray;padding:10px 0 2px;font-size:12px;line-height:1.9;overflow-wrap:anywhere}.details code{font-size:11px;white-space:normal}
.note{font-size:12px;margin-top:16px}#visible{font-variant-numeric:tabular-nums}
</style></head><body>
<header><div><h1>PT 种子活跃度</h1><p>更新：__UPDATED__ · 共 __COUNT__ 个不同种子 · __TASKCOUNT__ 条下载器任务 · 显示 <span id="visible">__COUNT__</span> 条<br>__CLIENTSUMMARY__</p></div>
<input id="search" type="search" placeholder="搜索站点、种子名、下载器或 H&R 状态…"></header>
<div class="wrapper"><table id="report"><thead><tr><th>站点</th><th>下载器</th><th>种子名称 / 详情</th><th>最近活动</th><th>下载完成时间</th><th>做种（小时）</th><th>分享率</th><th>上传量</th><th>H&R 本地评估</th></tr></thead>
__ROWS__</table></div>
<p class="note">相同站点与 Info Hash 的任务合并成一组，合计行与各下载器行分开；最近活动不一定代表实际上传。完成时间来自各下载器，合计行显示已知最早时间；Transmission 导入任务的完成时间不一定是 qBit 原始下载完成时间。合计做种时间可能包含两个客户端的重叠时间。合计分享率是本地估算，H&R 不代表站点判定。点击表头排序，点击种子名称展开详情。</p>
<script>
const table=document.getElementById('report'), groups=[...table.querySelectorAll('tbody.torrent-group')],search=document.getElementById('search');
let sortColumn=3,sortAsc=false;
function redraw(){
 const q=search.value.trim().toLocaleLowerCase();
 const shown=groups.filter(g=>g.textContent.toLocaleLowerCase().includes(q));
 shown.sort((a,b)=>{
  const ca=a.rows[0].cells[sortColumn],cb=b.rows[0].cells[sortColumn];
  const va=ca.dataset.sort??ca.textContent.trim(),vb=cb.dataset.sort??cb.textContent.trim();
  const na=Number(va),nb=Number(vb);
  const result=va!==''&&vb!==''&&!Number.isNaN(na)&&!Number.isNaN(nb)?na-nb:va.localeCompare(vb,'zh-CN');
  return sortAsc?result:-result;
 });
 table.replaceChildren(table.tHead,...shown);
 document.getElementById('visible').textContent=shown.length;
}
[...table.tHead.rows[0].cells].forEach((th,i)=>th.addEventListener('click',()=>{sortAsc=sortColumn===i?!sortAsc:true;sortColumn=i;redraw()}));
search.addEventListener('input',redraw);redraw();
</script></body></html>
HTML;
$page=strtr($page,['__UPDATED__'=>$updated,'__COUNT__'=>(string)$count,'__TASKCOUNT__'=>(string)$taskCount,'__CLIENTSUMMARY__'=>esc($clientSummary),'__ROWS__'=>$rows]);
if(!is_dir(dirname($htmlPath))&&!mkdir(dirname($htmlPath),0700,true))throw new RuntimeException('Cannot create HTML directory');
$tmp=tempnam(dirname($htmlPath),'.html-');
if($tmp===false)throw new RuntimeException('Cannot create HTML temporary file');
if(file_put_contents($tmp,$page)!==strlen($page)){fwrite(STDERR,"Cannot write report HTML\n");exit(1);}
chmod($tmp,0600);
if(!rename($tmp,$htmlPath)){fwrite(STDERR,"Cannot replace report HTML\n");exit(1);}
echo "HTML 报表: $htmlPath ($count 个不同种子，$taskCount 条下载器任务)\n";
