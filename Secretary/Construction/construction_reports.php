<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: login.php'); exit; }
$isAdmin = in_array($auth['role'], ['admin','ceo','general manager']);

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA GROUP OF COMPANY LIMITED','company_dept'=>'Construction & Projects Department','company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria'];
$logoUrl = './Client Order Form _ Okoya Food   mr jamal_files/logo_uigcps.jpg';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtCompact($n): string { $n=(float)$n; if($n>=1000000)return '₦'.number_format($n/1000000,1).'M'; if($n>=1000)return '₦'.number_format($n/1000,1).'K'; return '₦'.number_format($n,0); }
function matStatus(float $q, float $reorder): string { if($q<=0)return 'Out of Stock'; if($reorder>0 && $q<=$reorder)return 'Low Stock'; return 'In Stock'; }

/* ===== FETCH DATA ===== */
$materials=[]; try{ $materials=$pdo->query("SELECT * FROM construction_materials ORDER BY name ASC")->fetchAll(); }catch(Exception $ignore){}
$equipment=[]; try{ $equipment=$pdo->query("SELECT * FROM construction_equipment ORDER BY name ASC")->fetchAll(); }catch(Exception $ignore){}
$projects=[]; try{ $projects=$pdo->query("SELECT * FROM construction_projects ORDER BY end_date ASC")->fetchAll(); }catch(Exception $ignore){}

/* ===== MATERIALS ANALYTICS ===== */
$matIn=0;$matLow=0;$matOut=0;$stockValue=0.0;
$matByCat=[];
foreach($materials as $m){
    $q=(float)$m['quantity'];$r=(float)$m['reorder_level'];$st=matStatus($q,$r);
    if($st==='In Stock')$matIn++; elseif($st==='Low Stock')$matLow++; else $matOut++;
    $val=$q*(float)$m['unit_cost']; $stockValue+=$val;
    $cat=$m['category']??'Uncategorised';
    $matByCat[$cat]=($matByCat[$cat]??0)+$val;
}
arsort($matByCat);
$maxCatVal=max(array_values($matByCat)?:[1]);
$lowStockItems=array_filter($materials,function($m){ return matStatus((float)$m['quantity'],(float)$m['reorder_level'])!=='In Stock'; });
$totalMats=count($materials);

/* ===== DAILY STOCK MOVEMENTS (last 14 days) ===== */
$movDays=[];$inSeries=[];$outSeries=[];$movLabels=[];
for($i=13;$i>=0;$i--){ $d=date('Y-m-d',strtotime("-$i days")); $movDays[$d]=['in'=>0,'out'=>0]; }
try{
    $rows=$pdo->query("SELECT movement_date,movement_type,SUM(quantity) AS q FROM construction_material_movements WHERE movement_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY movement_date,movement_type")->fetchAll();
    foreach($rows as $r){ if(isset($movDays[$r['movement_date']])){ if($r['movement_type']==='In')$movDays[$r['movement_date']]['in']+=(float)$r['q']; else $movDays[$r['movement_date']]['out']+=(float)$r['q']; } }
}catch(Exception $ignore){}
foreach($movDays as $d=>$v){ $inSeries[]=$v['in']; $outSeries[]=$v['out']; $movLabels[]=date('d M',strtotime($d)); }
$movMax=max(array_merge($inSeries,$outSeries))?:1;

/* ===== EQUIPMENT ANALYTICS ===== */
$eqAvail=0;$eqInUse=0;$eqMaint=0;
foreach($equipment as $eq){ if($eq['status']==='Available')$eqAvail++; elseif($eq['status']==='In Use')$eqInUse++; else $eqMaint++; }
$totalEq=count($equipment);

/* ===== PROJECT ANALYTICS ===== */
$projData=[];
$projOnTrack=0;$projBehind=0;$projOverdue=0;$projCompleted=0;
$totalBudget=0;$totalSpent=0;$sumActual=0;
$now=time();
foreach($projects as $p){
    $start=$p['start_date']?strtotime($p['start_date']):null;
    $end=$p['end_date']?strtotime($p['end_date']):null;
    $actual=(int)$p['progress'];
    if($p['status']==='Completed'){ $status='Completed'; $actual=100; $expected=100; }
    else{
        $expected=($start&&$end&&$end>$start)?(int)min(100,max(0,round(($now-$start)/($end-$start)*100))):0;
        if($end&&$now>$end&&$actual<100)$status='Overdue';
        elseif($actual>=$expected)$status='On Track';
        else $status='Behind';
    }
    if($status==='On Track')$projOnTrack++; elseif($status==='Behind')$projBehind++; elseif($status==='Overdue')$projOverdue++; else $projCompleted++;
    $daysLeft=$end?max(0,(int)ceil(($end-$now)/86400)):null;
    $variance=$actual-$expected;
    $totalBudget+=(float)$p['budget'];$totalSpent+=(float)$p['spent'];$sumActual+=$actual;
    $projData[]=array_merge($p,['actual'=>$actual,'expected'=>$expected,'status2'=>$status,'days_left'=>$daysLeft,'variance'=>$variance]);
}
$totalProjects=count($projects);
$overallCompletion=$totalProjects>0?round($sumActual/$totalProjects):0;
$budgetUtil=$totalBudget>0?round($totalSpent/$totalBudget*100):0;

/* top projects by spent */
$topBySpent=$projData; usort($topBySpent,function($a,$b){return $b['spent']<=>$a['spent'];});
$topBySpent=array_slice($topBySpent,0,5);
$maxSpent=max(array_column($topBySpent,'spent')?:[1]);

/* upcoming maintenance */
$upcomingMaint=array_filter($equipment,function($eq){ return !empty($eq['next_maintenance']); });
usort($upcomingMaint,function($a,$b){ return strtotime($a['next_maintenance'])<=>strtotime($b['next_maintenance']); });
$upcomingMaint=array_slice($upcomingMaint,0,4);

/* ===== CHART BUILDERS (pure SVG/CSS) ===== */
function donutSVG($segs){
    if(empty($segs)) return '<div style="display:grid;place-items:center;width:140px;height:140px;color:var(--ink2);font-size:12px">No data</div>';
    $c='';
    foreach($segs as $s){
        $dash=$s['pct'].' '.(100-$s['pct']);
        $off=25-$s['offset'];
        $c.='<circle cx="20" cy="20" r="15.915" fill="none" stroke="'.$s['color'].'" stroke-width="5" stroke-dasharray="'.$dash.'" stroke-dashoffset="'.$off.'" stroke-linecap="butt"/>';
    }
    return '<svg viewBox="0 0 40 40" style="width:140px;height:140px"><circle cx="20" cy="20" r="15.915" fill="none" stroke="var(--line)" stroke-width="5"/>'.$c.'</svg>';
}
function makeSegs($raw,$total){
    $segs=[];$offset=0;
    if($total<=0)return $segs;
    foreach($raw as $s){ if($s[1]>0){ $pct=$s[1]/$total*100; $segs[]=['label'=>$s[0],'value'=>$s[1],'pct'=>$pct,'offset'=>$offset,'color'=>$s[2]]; $offset+=$pct; } }
    return $segs;
}
function gaugeSVG($pct,$color){
    $pct=min(100,max(0,$pct));
    $circ=2*M_PI*15.915;
    $dash=$circ*$pct/100;
    return '<svg viewBox="0 0 40 40" style="width:140px;height:140px;transform:rotate(-90deg)"><circle cx="20" cy="20" r="15.915" fill="none" stroke="var(--line)" stroke-width="5"/><circle cx="20" cy="20" r="15.915" fill="none" stroke="'.$color.'" stroke-width="5" stroke-dasharray="'.$dash.' '.$circ.'" stroke-linecap="round"/></svg>';
}
function areaChart($inS,$outS,$labels,$max){
    $w=640;$h=180;$padL=34;$padR=12;$padT=14;$padB=26;
    $max=$max*1.15;
    $n=count($inS);
    $xw=($w-$padL-$padR)/max(1,($n-1));
    $y=function($v)use($h,$padT,$padB,$max){ return $padT+($h-$padT-$padB)*(1-$v/$max); };
    $pi=[];$po=[];
    for($i=0;$i<$n;$i++){ $x=$padL+$i*$xw; $pi[]="$x,".$y($inS[$i]); $po[]="$x,".$y($outS[$i]); }
    $base=$h-$padB;
    $areaIn=implode(' ',$pi)." ".($padL+($n-1)*$xw).",$base ".$padL.",$base";
    $areaOut=implode(' ',$po)." ".($padL+($n-1)*$xw).",$base ".$padL.",$base";
    // gridlines
    $grid='';for($g=0;$g<=4;$g++){ $gy=$padT+($h-$padT-$padB)*$g/4; $grid.='<line x1="'.$padL.'" y1="'.$gy.'" x2="'.($w-$padR).'" y2="'.$gy.'" stroke="var(--line)" stroke-width="1" stroke-dasharray="3 3"/>'; $lbl=round($max*(1-$g/4)); $grid.='<text x="'.($padL-6).'" y="'.($gy+3).'" font-size="8" fill="var(--ink2)" text-anchor="end">'.$lbl.'</text>'; }
    // x labels
    $xl='';$step=max(1,ceil($n/7));
    for($i=0;$i<$n;$i+=$step){ $x=$padL+$i*$xw; $xl.='<text x="'.$x.'" y="'.($h-8).'" font-size="8" fill="var(--ink2)" text-anchor="middle">'.$labels[$i].'</text>'; }
    return '<svg viewBox="0 0 '.$w.' '.$h.'" style="width:100%;height:auto">
      '.$grid.'
      <polygon points="'.$areaIn.'" fill="rgba(22,110,69,.15)"/>
      <polyline points="'.implode(' ',$pi).'" fill="none" stroke="#166e45" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
      <polygon points="'.$areaOut.'" fill="rgba(217,122,22,.15)"/>
      <polyline points="'.implode(' ',$po).'" fill="none" stroke="#d97a16" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
      '.$xl.'
    </svg>';
}
function statusBadge($s){
    if($s==='Completed')return '<span class="pill p-done">Completed</span>';
    if($s==='On Track')return '<span class="pill p-ontrack">On Track</span>';
    if($s==='Behind')return '<span class="pill p-behind">Behind</span>';
    return '<span class="pill p-overdue">Overdue</span>';
}
function hbar($val,$max,$color){ $w=$max>0?round($val/$max*100):0; return '<div class="hbar"><div style="width:'.$w.'%;background:'.$color.'"></div></div>'; }

$eqSegs=makeSegs([['Available',$eqAvail,'#166e45'],['In Use',$eqInUse,'#2563eb'],['Maintenance',$eqMaint,'#d97a16']],$totalEq);
$matSegs=makeSegs([['In Stock',$matIn,'#166e45'],['Low Stock',$matLow,'#d97a16'],['Out of Stock',$matOut,'#c0392b']],$totalMats);
$projSegs=makeSegs([['On Track',$projOnTrack,'#166e45'],['Behind',$projBehind,'#d97a16'],['Overdue',$projOverdue,'#c0392b'],['Completed',$projCompleted,'#2563eb']],$totalProjects);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports &amp; Analytics | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="logo.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f4f4f2;--surface:#fff;--surface2:#f7f7f4;--ink:#1c2226;--ink2:#626b70;--line:#e1e3dd;--line2:#ccd2c8;--green:#166e45;--green2:#0e5233;--green-soft:#e2f2e8;--amber:#d97a16;--amber2:#b45f0a;--amber-soft:#fdeed7;--steel:#2b3439;--steel2:#1f262a;--red:#c0392b;--red-soft:#fdecea;--blue:#2563eb;--blue-soft:#e8effd;--shadow:0 1px 2px rgba(28,34,38,.05),0 12px 32px rgba(28,34,38,.10);--shadow-sm:0 1px 2px rgba(28,34,38,.07);--fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;--r:16px;--hazard:repeating-linear-gradient(45deg,var(--amber) 0 12px,var(--steel) 12px 24px)}
[data-theme="dark"]{--bg:#0d1214;--surface:#161d20;--surface2:#1a2428;--ink:#e8ecec;--ink2:#8b958f;--line:#263134;--line2:#334044;--green:#31c381;--green2:#22a468;--green-soft:#12352a;--amber:#f09a3a;--amber2:#d99a16;--amber-soft:#3a2a13;--steel:#39444a;--steel2:#2b3439;--red:#ff6f61;--red-soft:#3c1712;--blue:#6ea1ff;--blue-soft:#152540;--shadow:0 1px 2px rgba(0,0,0,.45),0 16px 40px rgba(0,0,0,.5);--shadow-sm:0 1px 2px rgba(0,0,0,.5)}
html{scroll-behavior:smooth}
body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.55;transition:background .3s,color .3s;-webkit-font-smoothing:antialiased}
body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(800px 500px at 88% -10%,color-mix(in srgb,var(--amber) 13%,transparent),transparent 60%),radial-gradient(600px 400px at -10% 40%,color-mix(in srgb,var(--steel) 10%,transparent),transparent 60%)}
svg{flex:none}button{font-family:inherit;cursor:pointer}input,select{font-family:inherit;font-size:15px;color:var(--ink)}a{text-decoration:none;color:inherit}
.container{max-width:1280px;margin:0 auto;padding:0 20px}
.mono{font-family:var(--fm)}
::selection{background:color-mix(in srgb,var(--amber) 30%,transparent)}

.topbar{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--surface) 85%,transparent);backdrop-filter:blur(16px);border-bottom:1px solid var(--line)}
.topbar .container{display:flex;align-items:center;gap:12px;padding-top:10px;padding-bottom:10px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:12px;margin-right:auto}
.logo-chip{width:44px;height:44px;border-radius:11px;background:#fff;display:grid;place-items:center;box-shadow:var(--shadow-sm);overflow:hidden;border:1px solid var(--line)}
.logo-chip img{width:38px;height:38px;object-fit:contain}
.brand strong{font-family:var(--fd);font-size:14px;font-weight:800;display:block;line-height:1.2}
.brand small{color:var(--ink2);font-size:10.5px;font-weight:600}
.back-link{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--ink2);padding:7px 14px;border-radius:10px;border:1px solid var(--line);background:var(--surface);transition:.2s}
.back-link:hover{color:var(--amber);border-color:var(--amber)}
.back-link svg{width:14px;height:14px}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:800;letter-spacing:.8px;padding:6px 10px;border-radius:99px}
.badge.amber{background:var(--amber-soft);color:var(--amber2)}
.theme-btn{width:38px;height:38px;border-radius:11px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:.25s;cursor:pointer}
.theme-btn:hover{color:var(--amber);border-color:var(--amber);transform:rotate(18deg)}
.theme-btn svg{width:17px;height:17px}

.page-header{margin:24px 0 4px;display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap}
.ph-strip{height:6px;background:var(--hazard);border-radius:99px;margin-bottom:18px}
.page-header h1{font-family:var(--fd);font-weight:800;font-size:clamp(22px,3vw,30px);display:flex;align-items:center;gap:10px}
.page-header h1 svg{width:26px;height:26px;stroke:var(--amber2)}
.page-header h1 em{font-style:normal;color:var(--amber2)}
.page-header p{color:var(--ink2);font-weight:600;margin-top:5px;font-size:13.5px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:none;border-radius:12px;font-weight:800;font-size:13px;padding:0 18px;height:42px;transition:.2s;cursor:pointer}
.btn svg{width:16px;height:16px}
.btn-ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--ink)}
.btn-ghost:hover{border-color:var(--amber);color:var(--amber2)}

/* KPI CARDS */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:20px}
.stat{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:18px;box-shadow:var(--shadow-sm);transition:.25s;position:relative;overflow:hidden}
.stat:hover{transform:translateY(-3px);box-shadow:var(--shadow)}
.stat .st-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.stat .ic{width:42px;height:42px;border-radius:12px;display:grid;place-items:center}
.stat .ic svg{width:20px;height:20px}
.stat .ic.a{background:var(--amber-soft)}.stat .ic.a svg{stroke:var(--amber2)}
.stat .ic.g{background:var(--green-soft)}.stat .ic.g svg{stroke:var(--green)}
.stat .ic.b{background:var(--blue-soft)}.stat .ic.b svg{stroke:var(--blue)}
.stat .ic.r{background:var(--red-soft)}.stat .ic.r svg{stroke:var(--red)}
.stat b{font-family:var(--fd);font-size:24px;font-weight:800;display:block;line-height:1.15}
.stat span{font-size:10.5px;font-weight:700;color:var(--ink2);letter-spacing:.4px;text-transform:uppercase}
.stat sub{font-size:10px;color:var(--ink2);font-weight:600}

.sec-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:28px 0 14px;flex-wrap:wrap}
.sec-head h2{font-family:var(--fd);font-size:17px;font-weight:800;display:flex;align-items:center;gap:9px}
.sec-head h2 svg{width:19px;height:19px;stroke:var(--amber2)}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:15px 20px;border-bottom:1px solid var(--line)}
.card-head h3{font-family:var(--fd);font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px}
.card-head h3 svg{width:17px;height:17px;stroke:var(--amber2)}
.card-body{padding:20px}

/* CHART GRIDS */
.chart-grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.chart-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.chart-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden}
.chart-title{display:flex;align-items:center;gap:8px;padding:15px 20px;border-bottom:1px solid var(--line);font-family:var(--fd);font-size:13.5px;font-weight:800}
.chart-title svg{width:17px;height:17px;stroke:var(--amber2)}
.chart-body{padding:20px}
.legend{display:flex;flex-direction:column;gap:9px}
.legend-item{display:flex;align-items:center;gap:9px;font-size:12.5px;font-weight:700}
.legend-item .dot{width:12px;height:12px;border-radius:4px;flex:none}
.legend-item b{font-family:var(--fm);margin-left:auto}
.donut-flex{display:flex;align-items:center;gap:20px;flex-wrap:wrap;justify-content:center}
.gauge-wrap{display:flex;flex-direction:column;align-items:center;gap:8px}
.gauge-center{position:relative;width:140px;height:140px;display:grid;place-items:center}
.gauge-center .gc{position:absolute;text-align:center}
.gauge-center .gc b{font-family:var(--fd);font-size:26px;font-weight:800;display:block}
.gauge-center .gc span{font-size:10px;font-weight:800;color:var(--ink2);text-transform:uppercase;letter-spacing:.4px}
.gauge-label{font-size:12px;font-weight:700;color:var(--ink2)}

/* HORIZONTAL BARS */
.hbar{height:10px;border-radius:99px;background:var(--line);overflow:hidden;margin-top:6px}
.hbar div{height:100%;border-radius:99px;transition:width .6s cubic-bezier(.2,.8,.3,1)}
.hb-list{display:flex;flex-direction:column;gap:14px}
.hb-item .hb-top{display:flex;justify-content:space-between;align-items:baseline}
.hb-item .hb-top .n{font-size:13px;font-weight:700}
.hb-item .hb-top .v{font-family:var(--fm);font-size:12px;font-weight:700;color:var(--amber2)}

/* PROJECT EVOLUTION */
.proj-list{padding:8px 20px 18px}
.proj-item{padding:16px 0;border-bottom:1px solid var(--line)}
.proj-item:last-child{border-bottom:none}
.proj-top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;flex-wrap:wrap}
.proj-top .pn{font-family:var(--fd);font-size:14px;font-weight:800}
.proj-top .pm{font-size:11px;color:var(--ink2);font-weight:600;margin-top:2px}
.proj-badges{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.track-bar{position:relative;height:12px;border-radius:99px;background:var(--line);overflow:hidden}
.track-fill{height:100%;border-radius:99px;transition:width .6s cubic-bezier(.2,.8,.3,1)}
.track-marker{position:absolute;top:-3px;width:3px;height:18px;background:var(--ink);border-radius:2px;opacity:.7}
.proj-meta{display:flex;justify-content:space-between;align-items:center;margin-top:8px;font-size:11px;font-weight:700;color:var(--ink2);flex-wrap:wrap;gap:6px}
.proj-meta .var{font-family:var(--fm)}
.var.ahead{color:var(--green)}.var.behind{color:var(--amber2)}.var.overdue{color:var(--red)}

.pill{font-size:9.5px;font-weight:800;padding:4px 10px;border-radius:99px;letter-spacing:.4px;white-space:nowrap}
.p-ontrack{background:var(--green-soft);color:var(--green)}
.p-behind{background:var(--amber-soft);color:var(--amber2)}
.p-overdue{background:var(--red-soft);color:var(--red)}
.p-done{background:var(--blue-soft);color:var(--blue)}

/* ALERTS */
.alert-list{padding:8px 20px 18px}
.alert-item{display:flex;align-items:center;justify-content:space-between;padding:11px 0;border-bottom:1px solid var(--line)}
.alert-item:last-child{border-bottom:none}
.alert-item .mn{font-size:13px;font-weight:700}
.alert-item small{display:block;font-size:11px;color:var(--ink2);font-weight:600}

.toasts{position:fixed;top:76px;right:18px;z-index:120;display:flex;flex-direction:column;gap:10px}
.toast{display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--amber);border-radius:12px;padding:13px 17px;font-size:13px;font-weight:700;box-shadow:var(--shadow);animation:slidein .3s;max-width:380px}
@keyframes slidein{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}

footer{margin-top:34px;border-top:1px solid var(--line);padding:22px 20px 30px;text-align:center;color:var(--ink2);font-size:12px;font-weight:700}

/* RESPONSIVE */
@media(max-width:1000px){.stats{grid-template-columns:repeat(2,1fr)}.chart-grid-3{grid-template-columns:1fr}.chart-grid-2{grid-template-columns:1fr}}
@media(max-width:768px){
  .topbar .badge{display:none}
  .brand strong{font-size:13px}
  .stats{grid-template-columns:1fr 1fr;gap:10px}
}
@media(max-width:480px){.stats{grid-template-columns:1fr}}
</style>
</head>
<body>

<header class="topbar"><div class="container">
  <div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div><strong><?php echo e($company['company_name']); ?></strong><small><?php echo e($company['company_dept']); ?></small></div></div>
  <a href="admin_construction_dashboard.php" class="back-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Construction Dashboard</a>
  <span class="badge amber">📈 REPORTS</span>
  <button class="theme-btn" id="themeBtn" title="Toggle theme"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button>
</div></header>

<div class="container" id="app">
  <div class="page-header">
    <div>
      <div class="ph-strip"></div>
      <h1><svg viewBox="0 0 24 24" fill="none"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg>Reports &amp; <em>Analytics</em></h1>
      <p>Deep analysis of materials, equipment, and project delivery performance.</p>
    </div>
    <button class="btn btn-ghost" onclick="window.print()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Print Report</button>
  </div>

  <!-- KPI CARDS -->
  <div class="stats">
    <div class="stat"><div class="st-top"><div class="ic a"><svg viewBox="0 0 24 24" fill="none"><path d="M2 20h20"/><path d="M5 20V8l7-4 7 4v12"/></svg></div></div><b><?php echo $totalProjects; ?></b><span>Projects</span><sub><?php echo $projCompleted; ?> completed</sub></div>
    <div class="stat"><div class="st-top"><div class="ic g"><svg viewBox="0 0 24 24" fill="none"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg></div></div><b><?php echo $totalMats; ?></b><span>Materials</span><sub><?php echo fmtCompact($stockValue); ?> value</sub></div>
    <div class="stat"><div class="st-top"><div class="ic b"><svg viewBox="0 0 24 24" fill="none"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></div></div><b><?php echo $totalEq; ?></b><span>Equipment</span><sub><?php echo $eqInUse; ?> in use</sub></div>
    <div class="stat"><div class="st-top"><div class="ic r"><svg viewBox="0 0 24 24" fill="none"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h16.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg></div></div><b><?php echo $projBehind+$projOverdue; ?></b><span>At Risk</span><sub><?php echo $projOverdue; ?> overdue</sub></div>
  </div>

  <!-- STOCK MOVEMENTS AREA CHART -->
  <div class="sec-head"><h2><svg viewBox="0 0 24 24" fill="none"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg>Stock Movements — Last 14 Days</h2></div>
  <div class="card">
    <div class="card-head"><h3>Stock In vs Stock Out (quantity)</h3>
      <div style="display:flex;gap:14px;font-size:11px;font-weight:700">
        <span style="display:flex;align-items:center;gap:6px"><span style="width:12px;height:12px;border-radius:3px;background:#166e45"></span>Stock In</span>
        <span style="display:flex;align-items:center;gap:6px"><span style="width:12px;height:12px;border-radius:3px;background:#d97a16"></span>Stock Out</span>
      </div>
    </div>
    <div class="card-body"><?php echo areaChart($inSeries,$outSeries,$movLabels,$movMax); ?></div>
  </div>

  <!-- DONUTS: EQUIPMENT / MATERIALS / PROJECTS -->
  <div class="sec-head"><h2><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9"/><path d="M12 3v9l6 3"/></svg>Status Distribution</h2></div>
  <div class="chart-grid-3">
    <div class="chart-card">
      <div class="chart-title"><svg viewBox="0 0 24 24" fill="none"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>Equipment Usage</div>
      <div class="chart-body donut-flex">
        <div class="gauge-center"><?php echo donutSVG($eqSegs); ?><div class="gc"><b><?php echo $totalEq; ?></b><span>Machines</span></div></div>
        <div class="legend">
          <div class="legend-item"><span class="dot" style="background:#166e45"></span>Available<b><?php echo $eqAvail; ?></b></div>
          <div class="legend-item"><span class="dot" style="background:#2563eb"></span>In Use<b><?php echo $eqInUse; ?></b></div>
          <div class="legend-item"><span class="dot" style="background:#d97a16"></span>Maintenance<b><?php echo $eqMaint; ?></b></div>
        </div>
      </div>
    </div>
    <div class="chart-card">
      <div class="chart-title"><svg viewBox="0 0 24 24" fill="none"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg>Material Stock Health</div>
      <div class="chart-body donut-flex">
        <div class="gauge-center"><?php echo donutSVG($matSegs); ?><div class="gc"><b><?php echo $totalMats; ?></b><span>Materials</span></div></div>
        <div class="legend">
          <div class="legend-item"><span class="dot" style="background:#166e45"></span>In Stock<b><?php echo $matIn; ?></b></div>
          <div class="legend-item"><span class="dot" style="background:#d97a16"></span>Low Stock<b><?php echo $matLow; ?></b></div>
          <div class="legend-item"><span class="dot" style="background:#c0392b"></span>Out of Stock<b><?php echo $matOut; ?></b></div>
        </div>
      </div>
    </div>
    <div class="chart-card">
      <div class="chart-title"><svg viewBox="0 0 24 24" fill="none"><path d="M2 20h20"/><path d="M5 20V8l7-4 7 4v12"/></svg>Project Status</div>
      <div class="chart-body donut-flex">
        <div class="gauge-center"><?php echo donutSVG($projSegs); ?><div class="gc"><b><?php echo $totalProjects; ?></b><span>Projects</span></div></div>
        <div class="legend">
          <div class="legend-item"><span class="dot" style="background:#166e45"></span>On Track<b><?php echo $projOnTrack; ?></b></div>
          <div class="legend-item"><span class="dot" style="background:#d97a16"></span>Behind<b><?php echo $projBehind; ?></b></div>
          <div class="legend-item"><span class="dot" style="background:#c0392b"></span>Overdue<b><?php echo $projOverdue; ?></b></div>
          <div class="legend-item"><span class="dot" style="background:#2563eb"></span>Completed<b><?php echo $projCompleted; ?></b></div>
        </div>
      </div>
    </div>
  </div>

  <!-- HORIZONTAL BARS -->
  <div class="chart-grid-2" style="margin-top:16px">
    <div class="chart-card">
      <div class="chart-title"><svg viewBox="0 0 24 24" fill="none"><path d="M3 3v18h18"/><path d="M7 8h8M7 12h6M7 16h4"/></svg>Stock Value by Category</div>
      <div class="chart-body hb-list">
        <?php if(empty($matByCat)): ?>
          <div style="text-align:center;color:var(--ink2)">No material value recorded.</div>
        <?php else: foreach($matByCat as $cat=>$val): ?>
          <div class="hb-item">
            <div class="hb-top"><span class="n"><?php echo e($cat); ?></span><span class="v"><?php echo fmtCompact($val); ?></span></div>
            <?php echo hbar($val,$maxCatVal,'linear-gradient(90deg,var(--amber),#ffb46b)'); ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <div class="chart-card">
      <div class="chart-title"><svg viewBox="0 0 24 24" fill="none"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg>Top Projects by Spend</div>
      <div class="chart-body hb-list">
        <?php if(empty($topBySpent)): ?>
          <div style="text-align:center;color:var(--ink2)">No project spend recorded.</div>
        <?php else: foreach($topBySpent as $p): ?>
          <div class="hb-item">
            <div class="hb-top"><span class="n"><?php echo e($p['name']); ?></span><span class="v"><?php echo fmtCompact($p['spent']); ?></span></div>
            <?php echo hbar((float)$p['spent'],(float)$maxSpent,'linear-gradient(90deg,#166e45,#31c381)'); ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- GAUGES -->
  <div class="chart-grid-2" style="margin-top:16px">
    <div class="chart-card">
      <div class="chart-title"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l4 2"/></svg>Overall Project Completion</div>
      <div class="chart-body donut-flex">
        <div class="gauge-wrap">
          <div class="gauge-center"><?php echo gaugeSVG($overallCompletion,'#166e45'); ?><div class="gc"><b><?php echo $overallCompletion; ?>%</b><span>Complete</span></div></div>
          <div class="gauge-label">Average progress across <?php echo $totalProjects; ?> project(s)</div>
        </div>
      </div>
    </div>
    <div class="chart-card">
      <div class="chart-title"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 9.5c0-.8.9-1.5 3-1.5s3 .7 3 1.5-.9 1.5-3 1.5-3 .7-3 1.5.9 1.5 3 1.5 3-.7 3-1.5"/></svg>Budget Utilisation</div>
      <div class="chart-body donut-flex">
        <div class="gauge-wrap">
          <div class="gauge-center"><?php echo gaugeSVG($budgetUtil,'#d97a16'); ?><div class="gc"><b><?php echo $budgetUtil; ?>%</b><span>Spent</span></div></div>
          <div class="gauge-label"><?php echo fmtCompact($totalSpent); ?> of <?php echo fmtCompact($totalBudget); ?> budget used</div>
        </div>
      </div>
    </div>
  </div>

  <!-- PROJECT EVOLUTION -->
  <div class="sec-head"><h2><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l4 2"/></svg>Project Evolution vs Deadline</h2></div>
  <div class="card">
    <div class="card-head"><h3>Actual progress vs expected (marker = expected today)</h3></div>
    <div class="proj-list">
      <?php if(empty($projData)): ?>
        <div style="padding:40px 0;text-align:center;color:var(--ink2)"><div style="font-size:42px;margin-bottom:10px">📊</div><p style="font-weight:700">No projects to analyze</p></div>
      <?php else: foreach($projData as $p):
        $fillColor=$p['status2']==='Completed'?'var(--blue)':($p['status2']==='On Track'?'var(--green)':($p['status2']==='Behind'?'var(--amber)':'var(--red)'));
      ?>
        <div class="proj-item">
          <div class="proj-top">
            <div><div class="pn"><?php echo e($p['name']); ?></div><div class="pm"><?php echo e($p['site']??'—'); ?> · <?php echo e($p['manager']??'—'); ?></div></div>
            <div class="proj-badges">
              <?php echo statusBadge($p['status2']); ?>
              <?php if($p['status2']!=='Completed'&&$p['days_left']!==null): ?><span class="mono" style="font-size:11px;font-weight:700;color:<?php echo $p['days_left']<=7?'var(--red)':'var(--ink2)'; ?>"><?php echo $p['days_left']; ?> days left</span><?php endif; ?>
            </div>
          </div>
          <div class="track-bar">
            <div class="track-fill" style="width:<?php echo $p['actual']; ?>%;background:<?php echo $fillColor; ?>"></div>
            <?php if($p['status2']!=='Completed'): ?><div class="track-marker" style="left:<?php echo $p['expected']; ?>%"></div><?php endif; ?>
          </div>
          <div class="proj-meta">
            <span>Actual <b class="mono"><?php echo $p['actual']; ?>%</b> · Expected <b class="mono"><?php echo $p['expected']; ?>%</b></span>
            <span class="var <?php echo $p['variance']>=0?'ahead':($p['status2']==='Overdue'?'overdue':'behind'); ?>"><?php echo $p['variance']>=0?'+':''; ?><?php echo $p['variance']; ?>% <?php echo $p['variance']>=0?'ahead':'behind'; ?></span>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- ALERTS -->
  <div class="sec-head"><h2><svg viewBox="0 0 24 24" fill="none"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h16.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg>Alerts &amp; Attention</h2></div>
  <div class="card">
    <div class="alert-list">
      <?php $hasAlert=false; ?>
      <?php foreach($lowStockItems as $m): $hasAlert=true; $st=matStatus((float)$m['quantity'],(float)$m['reorder_level']); ?>
        <div class="alert-item">
          <div><span class="mn"><?php echo e($m['name']); ?></span><small><?php echo number_format((float)$m['quantity']); ?> <?php echo e($m['unit']); ?> left · reorder at <?php echo number_format((float)$m['reorder_level']); ?></small></div>
          <span class="pill <?php echo $st==='Out of Stock'?'p-overdue':'p-behind'; ?>"><?php echo e($st); ?></span>
        </div>
      <?php endforeach; ?>
      <?php foreach($projData as $p): if($p['status2']==='Overdue'||$p['status2']==='Behind'): $hasAlert=true; ?>
        <div class="alert-item">
          <div><span class="mn"><?php echo e($p['name']); ?></span><small><?php echo e($p['site']??'—'); ?> · <?php echo $p['variance']; ?>% behind schedule</small></div>
          <span class="pill <?php echo $p['status2']==='Overdue'?'p-overdue':'p-behind'; ?>"><?php echo e($p['status2']); ?></span>
        </div>
      <?php endif; endforeach; ?>
      <?php if(!$hasAlert): ?>
        <div style="padding:24px 0;text-align:center;color:var(--ink2)">All clear — no alerts. Materials are healthy and projects are on track.</div>
      <?php endif; ?>
    </div>
  </div>

</div>

<footer><div>&copy; 2026 <?php echo e($company['company_name']); ?> — Reports &amp; Analytics</div>
       <div> <h5> Developped by Lawani Djamiou Alade </h5> </div>
</footer>
<div class="toasts" id="toastWrap"></div>

<script>
const $=id=>document.getElementById(id);
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));
</script>
</body>
</html>