<?php
declare(strict_types=1);

/* ============================================================
   NOBLE CENTER — tout dans UNE seule page (index.php)
   Base de données MySQL : "noble"
   ============================================================ */
const DB_HOST = 'localhost';
const DB_NAME = 'noble';
const DB_USER = 'root';             // XAMPP : root
const DB_PASS = '';                 // XAMPP : mot de passe vide
const ADMIN_PASSWORD = 'CHANGE_ME'; // utilisé seulement si la base est vide (aucun compte admin) — 6+ caractères
const COOKIE_SECURE = false;        // true si le site est en HTTPS

/* ===================== PARTIE SERVEUR (API) ===================== */
if (isset($_GET['r'])) {

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function out(int $c, $d): void { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function bad(string $m, int $c = 400): void { out($c, ['error' => $m]); }
set_exception_handler(function ($e) { error_log((string)$e); bad('Erreur serveur', 500); });

/* ---- session + CSRF ---- */
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', '604800');
session_name('noble');
session_set_cookie_params(['lifetime' => 604800, 'path' => '/', 'secure' => COOKIE_SECURE, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
$m = $_SERVER['REQUEST_METHOD'];
if ($m !== 'GET' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'noble') bad('Requête refusée', 403);

/* ---- base de données ---- */
$dsn = fn(bool $db) => 'mysql:host=' . DB_HOST . ($db ? ';dbname=' . DB_NAME : '') . ';charset=utf8mb4';
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
try { $pdo = new PDO($dsn(true), DB_USER, DB_PASS, $opt); }
catch (PDOException $e) {
    $code = (int)($e->errorInfo[1] ?? 0);
    if ($code === 1049) {
        try {
            $t = new PDO($dsn(false), DB_USER, DB_PASS, $opt);
            $t->exec('CREATE DATABASE `' . str_replace('`', '', DB_NAME) . '` CHARACTER SET utf8mb4');
            $pdo = new PDO($dsn(true), DB_USER, DB_PASS, $opt);
        } catch (PDOException $e2) { bad("Impossible de créer la base. Créez-la dans phpMyAdmin (nom: " . DB_NAME . ").", 500); }
    } else bad($code === 1045 ? 'DB_USER ou DB_PASS incorrect (index.php)' : 'Connexion MySQL impossible : vérifiez index.php', 500);
}

function run(string $sql, array $a = []): PDOStatement { global $pdo; $s = $pdo->prepare($sql); $s->execute($a); return $s; }
function row(string $sql, array $a = []) { return run($sql, $a)->fetch(); }
function rows(string $sql, array $a = []): array { return run($sql, $a)->fetchAll(); }
function bump(): void { global $pdo; $pdo->exec('UPDATE meta SET ver = ver + 1'); }
function body(): array { $d = json_decode(file_get_contents('php://input') ?: '', true); return is_array($d) ? $d : []; }

function install(PDO $pdo): void {
    if (ADMIN_PASSWORD === 'CHANGE_ME' || strlen(ADMIN_PASSWORD) < 6) bad('Ouvrez index.php et changez ADMIN_PASSWORD en haut du fichier (6+ caractères), puis rechargez la page.', 500);
    $S = 'VARCHAR(100) NOT NULL'; $ID = 'id INT AUTO_INCREMENT PRIMARY KEY';
    foreach ([
        "CREATE TABLE IF NOT EXISTS users($ID, username VARCHAR(20) NOT NULL UNIQUE, pass_hash VARCHAR(255) NOT NULL, role ENUM('admin','staff') NOT NULL DEFAULT 'staff')",
        "CREATE TABLE IF NOT EXISTS students($ID, n $S, g $S, l $S)",
        "CREATE TABLE IF NOT EXISTS teachers($ID, n $S, m $S)",
        "CREATE TABLE IF NOT EXISTS grps($ID, n $S, l $S, p $S, c INT NOT NULL)",
        "CREATE TABLE IF NOT EXISTS payments($ID, n $S, g $S, a INT NOT NULL, s ENUM('Retard','Payé') NOT NULL DEFAULT 'Retard')",
        "CREATE TABLE IF NOT EXISTS alerts($ID, t VARCHAR(200) NOT NULL, c VARCHAR(9) NOT NULL)",
        "CREATE TABLE IF NOT EXISTS schedule($ID, c TINYINT NOT NULL, r TINYINT NOT NULL, s TINYINT NOT NULL, t $S, g $S, p $S, k VARCHAR(9) NOT NULL)",
        "CREATE TABLE IF NOT EXISTS fails(ip VARCHAR(45) NOT NULL, t INT NOT NULL, INDEX(ip))",
    ] as $q) $pdo->exec($q);
    $pdo->prepare('INSERT INTO users(username,pass_hash,role) VALUES(?,?,?)')->execute(['admin', password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT), 'admin']);
    $pdo->exec('CREATE TABLE IF NOT EXISTS meta(ver INT NOT NULL)');
    $pdo->exec('INSERT INTO meta(ver) VALUES(1)');
}
try { $pdo->query('SELECT ver FROM meta LIMIT 1'); } catch (PDOException $e) { install($pdo); }

/* ---- tables exposées ---- */
$TBL = ['students' => 'students', 'teachers' => 'teachers', 'groups' => 'grps', 'pay' => 'payments', 'sched' => 'schedule'];
$FIELDS = ['students' => ['n', 'g', 'l'], 'teachers' => ['n', 'm'], 'groups' => ['n', 'l', 'p', 'c'], 'pay' => ['n', 'g', 'a'], 'sched' => ['c', 'r', 's', 't', 'g', 'p', 'k']];
$NUMF = ['groups' => ['c'], 'pay' => ['a'], 'sched' => ['c', 'r', 's']];
$INTS = ['students' => ['id'], 'teachers' => ['id'], 'groups' => ['id', 'c'], 'pay' => ['id', 'a'], 'sched' => ['id', 'c', 'r', 's'], 'alerts' => ['id']];
$ADMIN_ONLY_ADD = ['teachers', 'pay', 'sched'];

function slen(string $s): int { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); }
function ints(array $rows, array $keys): array { foreach ($rows as &$r) foreach ($keys as $k) $r[$k] = (int)$r[$k]; return $rows; }
function clean(string $k, array $b): ?array {
    global $FIELDS, $NUMF; $o = [];
    foreach ($FIELDS[$k] as $f) {
        $v = $b[$f] ?? null;
        if (in_array($f, $NUMF[$k] ?? [], true)) {
            if (is_string($v) && preg_match('/^\d+$/', $v)) $v = (int)$v;
            if (is_float($v) && floor($v) == $v) $v = (int)$v;
            if (!is_int($v) || $v < 0 || $v > 2000000000) return null;
        } else { $v = trim((string)($v ?? '')); if ($v === '' || slen($v) > 100) return null; }
        $o[$f] = $v;
    }
    if ($k === 'sched' && !($o['c'] >= 2 && $o['c'] <= 6 && $o['r'] >= 1 && $o['r'] <= 10 && $o['s'] >= 1 && $o['s'] <= 4 && $o['r'] + $o['s'] - 1 <= 10 && preg_match('/^#[0-9a-f]{6}$/i', $o['k']))) return null;
    return $o;
}
function clash(array $o, int $id = 0): ?string {
    return row('SELECT id FROM schedule WHERE c=? AND r<? AND ?<r+s AND id<>? LIMIT 1', [$o['c'], $o['r'] + $o['s'], $o['r'], $id]) ? 'Chevauchement : créneau déjà occupé' : null;
}

/* ---- routage : api.php?r=ressource/id/action ---- */
$p = explode('/', trim((string)($_GET['r'] ?? ''), '/'));
$a = $p[0]; $id = (int)($p[1] ?? 0); $sub = $p[2] ?? '';
$NAME = '/^[\w.-]{3,20}$/';

/* connexion (sans session) */
if ($a === 'login' && $m === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    run('DELETE FROM fails WHERE t < ?', [time() - 900]);
    if ((int)row('SELECT COUNT(*) c FROM fails WHERE ip=?', [$ip])['c'] >= 10) bad('Trop de tentatives, réessayez plus tard', 429);
    $b = body(); $u = row('SELECT * FROM users WHERE username=?', [(string)($b['username'] ?? '')]);
    if (!$u || !password_verify((string)($b['password'] ?? ''), $u['pass_hash'])) { run('INSERT INTO fails VALUES(?,?)', [$ip, time()]); bad('Identifiants incorrects', 401); }
    session_regenerate_id(true); $_SESSION['uid'] = (int)$u['id'];
    out(200, ['username' => $u['username'], 'role' => $u['role']]);
}
if ($a === 'signup' && $m === 'POST') {
    $b = body(); $n = (string)($b['username'] ?? ''); $pw = (string)($b['password'] ?? '');
    if (!preg_match($NAME, $n)) bad("Nom d'utilisateur : 3-20 caractères (lettres, chiffres, . _ -)");
    if (strlen($pw) < 6) bad('Mot de passe : 6 caractères minimum');
    try { run('INSERT INTO users(username,pass_hash,role) VALUES(?,?,?)', [$n, password_hash($pw, PASSWORD_DEFAULT), 'staff']); }
    catch (PDOException $e) { if ($e->getCode() === '23000') bad("Ce nom d'utilisateur existe déjà"); throw $e; }
    session_regenerate_id(true); $_SESSION['uid'] = (int)$pdo->lastInsertId();
    out(200, ['username' => $n, 'role' => 'staff']);
}
if ($a === 'logout') { $_SESSION = []; session_destroy(); out(200, ['ok' => 1]); }

/* à partir d'ici : connexion obligatoire */
$me = isset($_SESSION['uid']) ? row('SELECT id,username,role FROM users WHERE id=?', [$_SESSION['uid']]) : false;
if (!$me) bad('Non connecté', 401);
$admin = $me['role'] === 'admin';
function needAdmin(): void { global $admin; if (!$admin) bad('Réservé aux admins', 403); }
function nAdmins(): int { return (int)row("SELECT COUNT(*) c FROM users WHERE role='admin'")['c']; }

if ($a === 'me') out(200, ['username' => $me['username'], 'role' => $me['role']]);
if ($a === 'ver') out(200, ['ver' => (int)row('SELECT ver FROM meta')['ver']]);
if ($a === 'password' && $m === 'POST') {
    $pw = (string)(body()['password'] ?? ''); if (strlen($pw) < 6) bad('Minimum 6 caractères');
    run('UPDATE users SET pass_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $me['id']]); out(200, ['ok' => 1]);
}

/* utilisateurs (admin) */
if ($a === 'users') {
    needAdmin();
    if ($m === 'GET') out(200, ints(rows('SELECT id,username,role FROM users ORDER BY id'), ['id']));
    if ($m === 'POST') {
        $b = body(); $n = (string)($b['username'] ?? ''); $pw = (string)($b['password'] ?? ''); $r = $b['role'] ?? '';
        if (!preg_match($NAME, $n) || strlen($pw) < 6 || !in_array($r, ['admin', 'staff'], true)) bad('Données invalides');
        try { run('INSERT INTO users(username,pass_hash,role) VALUES(?,?,?)', [$n, password_hash($pw, PASSWORD_DEFAULT), $r]); }
        catch (PDOException $e) { if ($e->getCode() === '23000') bad('Nom déjà utilisé'); throw $e; }
        out(200, ['ok' => 1]);
    }
    $u = row('SELECT role FROM users WHERE id=?', [$id]); if (!$u) bad('Introuvable', 404);
    if ($m === 'PUT') {
        $r = body()['role'] ?? ''; if (!in_array($r, ['admin', 'staff'], true)) bad('Rôle invalide');
        if ($u['role'] === 'admin' && $r !== 'admin' && nAdmins() < 2) bad('Il faut au moins un admin');
        run('UPDATE users SET role=? WHERE id=?', [$r, $id]); out(200, ['ok' => 1]);
    }
    if ($m === 'DELETE') {
        if ($u['role'] === 'admin' && nAdmins() < 2) bad('Il faut au moins un admin');
        run('DELETE FROM users WHERE id=?', [$id]); out(200, ['ok' => 1]);
    }
}

/* données */
if ($a === 'state' && $m === 'GET') {
    $o = [];
    foreach ($TBL as $k => $t) $o[$k] = ints(rows("SELECT * FROM $t ORDER BY id " . ($k === 'sched' ? 'ASC' : 'DESC')), $INTS[$k]);
    $o['alerts'] = ints(rows('SELECT id,t,c FROM alerts ORDER BY id DESC LIMIT 50'), ['id']);
    out(200, $o);
}
if ($a === 'alerts' && $m === 'DELETE' && $id) { run('DELETE FROM alerts WHERE id=?', [$id]); bump(); out(200, ['ok' => 1]); }
if ($a === 'pay' && $sub === 'paid' && $m === 'POST' && $id) {
    needAdmin(); $p0 = row('SELECT n FROM payments WHERE id=?', [$id]); if (!$p0) bad('Introuvable', 404);
    run("UPDATE payments SET s='Payé' WHERE id=?", [$id]);
    run('INSERT INTO alerts(t,c) VALUES(?,?)', ['Paiement reçu : ' . $p0['n'], '#2fb36b']); bump(); out(200, ['ok' => 1]);
}
if (isset($TBL[$a])) {
    $t = $TBL[$a];
    if ($m === 'POST' && !$id) {
        if (in_array($a, $ADMIN_ONLY_ADD, true)) needAdmin();
        $o = clean($a, body()); if (!$o) bad('Données invalides');
        if ($a === 'sched' && ($e = clash($o))) bad($e);
        run("INSERT INTO $t(" . implode(',', array_keys($o)) . ') VALUES(' . implode(',', array_fill(0, count($o), '?')) . ')', array_values($o));
        $nid = (int)$pdo->lastInsertId(); bump(); out(200, ['id' => $nid]);
    }
    if ($m === 'PUT' && $a === 'sched' && $id) {
        needAdmin(); $o = clean('sched', body()); if (!$o) bad('Données invalides');
        if ($e = clash($o, $id)) bad($e);
        run('UPDATE schedule SET ' . implode(',', array_map(fn($k) => "$k=?", array_keys($o))) . ' WHERE id=?', [...array_values($o), $id]);
        bump(); out(200, ['ok' => 1]);
    }
    if ($m === 'DELETE' && $id) { needAdmin(); run("DELETE FROM $t WHERE id=?", [$id]); bump(); out(200, ['ok' => 1]); }
}
bad('Introuvable', 404);
    exit;
}
/* ===================== PARTIE PAGE (HTML) ===================== */
?>
<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Noble Center - Dashboard</title>
<link rel="icon" href="image.png">
<style>
:root{--bg:#eef2f8;--card:#fff;--tx:#14213d;--mu:#64708a;--bd:#e3e8f1;--ac:#2f6fe4;--acs:#e6eefc;--red:#dc3545;--redbg:#fde8ea;--ok:#198754;--okbg:#e1f6ea;box-sizing:border-box;padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px)}
@media(prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#0e1424;--card:#171f35;--tx:#e8edf8;--mu:#93a0bd;--bd:#26304c;--acs:#1f2d52;--redbg:#40202a;--okbg:#17382a}}
:root[data-theme="dark"]{--bg:#0e1424;--card:#171f35;--tx:#e8edf8;--mu:#93a0bd;--bd:#26304c;--acs:#1f2d52;--redbg:#40202a;--okbg:#17382a}
html{scroll-padding-top:env(safe-area-inset-top,0px)}*{box-sizing:border-box}[hidden]{display:none!important}
body{margin:0;background:var(--bg);color:var(--tx);font:14px "Inter","Segoe UI",system-ui,sans-serif}
.app{display:grid;grid-template-columns:210px 1fr;min-height:100vh}
aside{padding:20px 14px;background:var(--card);border-right:1px solid var(--bd)}
.logo{text-align:center;font-weight:700;font-size:16px;color:var(--ac);margin-bottom:22px}.logo span{display:block;font-size:30px}
nav a{display:flex;gap:10px;padding:10px 12px;border-radius:8px;color:var(--mu);margin-bottom:4px;cursor:pointer}
nav a.on{background:var(--ac);color:#fff}nav a:hover:not(.on){background:var(--acs)}
main{padding:22px 26px;min-width:0}
header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:18px}
h1{font-size:26px;margin:0}h1 small{font-weight:400}h2{font-size:17px;margin:0 0 12px}
.top{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.mu{color:var(--mu)}
.user{background:var(--card);border:1px solid var(--bd);padding:7px 14px;border-radius:20px}
.card{background:var(--card);border:1px solid var(--bd);border-radius:14px;padding:16px;min-width:0}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:16px}
.kpi{display:flex;gap:12px;align-items:center}.kpi p{margin:0;color:var(--mu)}.kpi b{font-size:26px}
.ic{width:44px;height:44px;border-radius:10px;background:var(--acs);display:grid;place-items:center;font-size:20px}
.g2{display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:16px}.g3{display:grid;grid-template-columns:1fr 2fr;gap:16px}
.col{display:grid;gap:16px;align-content:start}.wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}th{text-align:left;color:var(--mu);font-size:12px;padding:8px 6px}td{padding:10px 6px;border-top:1px solid var(--bd)}
.tag,.ok{padding:2px 10px;border-radius:5px;font-size:12px;font-weight:600}.tag{background:var(--redbg);color:var(--red)}.ok{background:var(--okbg);color:var(--ok)}
.tt{display:grid;grid-template-columns:36px repeat(5,1fr);font-size:11px;border-top:1px solid var(--bd);overflow-x:auto}
.tt .h{grid-column:1;color:var(--mu)}
.ev{margin:2px;border-radius:5px;color:#fff;padding:4px 6px;line-height:1.25;min-width:90px;cursor:pointer}.ev b{display:block}.ev:hover{filter:brightness(1.1)}.ev.sel{outline:3px solid var(--tx)}
.form{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
input,select{font:inherit;padding:8px 10px;border:1px solid var(--bd);border-radius:8px;background:var(--card);color:var(--tx);min-width:0}
.btn{font:inherit;background:var(--ac);color:#fff;border:0;border-radius:8px;padding:8px 14px;cursor:pointer}.btn:disabled{opacity:.6}
.btn.g{background:var(--acs);color:var(--ac)}.btn.r{background:var(--redbg);color:var(--red)}
.donut{display:flex;gap:20px;align-items:center;flex-wrap:wrap}.donut circle{cursor:pointer;transition:stroke-width .2s}.donut circle:hover{stroke-width:8}
.lg{display:grid;gap:8px}.lg i{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:8px}
.al{list-style:none;margin:0;padding:0}.al li{display:flex;gap:10px;align-items:center;padding:10px 6px;border-top:1px solid var(--bd);cursor:pointer}.al li:first-child{border:0}.al li:hover{background:var(--acs)}
.dot{width:12px;height:12px;border-radius:50%;flex:none}.bar{height:10px;border-radius:5px;transition:width .5s}
.toast{position:fixed;bottom:calc(20px + env(safe-area-inset-bottom,0px));left:50%;transform:translateX(-50%);background:#14213d;color:#fff;padding:10px 18px;border-radius:10px;opacity:0;transition:.25s;pointer-events:none;z-index:9}.toast.on{opacity:1}
#login,#boot{min-height:100vh;place-items:center;padding:20px}#login{display:grid}#boot{display:grid;color:var(--mu)}
.lbox{width:100%;max-width:380px;text-align:center;padding:30px 26px}.lbox .big{font-size:44px}.lbox h2{font-size:22px;margin:6px 0 4px}.lbox p{color:var(--mu);margin:0 0 18px}
.lbox input{width:100%;margin-bottom:10px}.lbox .btn{width:100%;padding:11px}.err{color:var(--red);min-height:20px;font-size:13px;margin-bottom:6px}
.hint{font-size:12px;color:var(--mu);margin-top:14px}.hint a{color:var(--ac)}.shake{animation:sh .3s}@keyframes sh{25%{transform:translateX(-8px)}75%{transform:translateX(8px)}}
@media(max-width:1000px){.kpis{grid-template-columns:1fr 1fr}.g2,.g3{grid-template-columns:1fr}}
@media(max-width:700px){.app{grid-template-columns:minmax(0,1fr)}aside{min-width:0}aside{border:0;border-bottom:1px solid var(--bd)}nav{display:flex;overflow-x:auto;gap:4px}nav a{white-space:nowrap}.logo{display:none}main{padding:16px}.kpis{grid-template-columns:1fr}}
</style></head><body>
<div id="login" hidden><div class="card lbox" id="lbox"><div class="big">🎓</div><h2 id="lt"></h2><p id="ls"></p>
<input id="lu" placeholder="Nom d'utilisateur" autocomplete="username">
<input id="lp" type="password" placeholder="Mot de passe" autocomplete="current-password">
<input id="lc" type="password" placeholder="Confirmer le mot de passe" autocomplete="new-password" hidden>
<div class="err" id="le"></div><button class="btn" id="lb"></button><div class="hint" id="lw"></div></div></div>
<div id="boot">Chargement…</div>
<div class="app" id="app" hidden>
<aside><div class="logo"><span>🎓</span>Noble Center</div><nav id="nav"></nav></aside>
<main><header><h1 id="h1"></h1><div class="top"><span class="mu" id="clock"></span><div class="user" id="uname"></div><button class="btn g" onclick="logout()">Déconnexion</button></div></header><div id="view"></div></main>
</div><div class="toast" id="toast"></div>
<script>
const $=s=>document.querySelector(s),clone=o=>JSON.parse(JSON.stringify(o)),
esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),
fmt=n=>(+n).toLocaleString('en-US')+' DH',
toast=m=>{const t=$('#toast');t.textContent=m;t.classList.add('on');clearTimeout(toast.t);toast.t=setTimeout(()=>t.classList.remove('on'),2200)},
lsGet=(k,d)=>{try{return JSON.parse(localStorage.getItem(k))||d}catch(e){return d}},
lsSet=(k,v)=>{try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}};

/* ---------- data ---------- */
let D={students:[],teachers:[],groups:[],pay:[],alerts:[],sched:[]},USERS=[],ME=null,page='dash',q='',ei=-1;
const isAdmin=()=>!!ME&&ME.r=='admin',paid=()=>D.pay.filter(x=>x.s=='Payé').reduce((a,x)=>a+x.a,0),total=()=>paid(),
occ=()=>Math.round(D.sched.reduce((a,e)=>a+e.s,0)*100/50);
const api=async(m,u,b)=>{const r=await fetch(u.replace('/api/','index.php?r='),{method:m,headers:{...(b?{'Content-Type':'application/json'}:{}),'X-Requested-With':'noble'},body:b?JSON.stringify(b):undefined,credentials:'same-origin'});
const j=await r.json().catch(()=>({}));if(r.status==401&&u!='/api/login'&&u!='/api/me'){ME=null;show()}if(!r.ok)throw new Error(j.error||'Erreur');return j};
const load=async()=>{D=await api('GET','/api/state')};
const act=async(f,ok)=>{try{await f();await load();ok&&toast(ok);V[page]()}catch(e){toast(e.message)}};
function seg(){const n=D.students.length;if(!n)return[['Aucun étudiant',100,'#cbd5e1',0]];const m={};D.students.forEach(x=>{const k=x.l.trim()||'Autres';m[k]=(m[k]||0)+1});
const C=['#2f6fe4','#2fb36b','#6b7a90','#e0902a','#8b4fd8'],o=Object.entries(m).sort((a,b)=>b[1]-a[1]).slice(0,5).map(([k,c],i)=>[k,Math.round(c*100/n),C[i],c]);
o[o.length-1][1]+=100-o.reduce((a,x)=>a+x[1],0);return o}
function applyTheme(){let t='';try{t=localStorage.getItem('noble_theme')||''}catch(e){}t?document.documentElement.setAttribute('data-theme',t):document.documentElement.removeAttribute('data-theme')}
function setTheme(t){try{localStorage.setItem('noble_theme',t)}catch(e){}applyTheme()}
setInterval(()=>$('#clock').textContent='Rabat — '+new Date().toLocaleString('fr-FR',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}),1000);

/* ---------- navigation ---------- */
const PAGES=[['dash','▦','Dashboard',1],['stu','👤','Étudiants',1],['tea','🧑‍🏫','Professeurs'],['grp','👥','Groupes',1],['sch','📅','Emploi du Temps',1],['pay','💳','Paiements'],['rep','📊','Rapports'],['usr','🔑','Utilisateurs'],['set','⚙','Paramètres',1]];
const allowed=()=>PAGES.filter(p=>isAdmin()||p[3]);
function go(p){if(!allowed().some(x=>x[0]==p))p='dash';page=p;q='';
$('#nav').innerHTML=allowed().map(([k,i,l])=>`<a class="${k==p?'on':''}" onclick="go('${k}')">${i} ${l}</a>`).join('');
const m=new Date().toLocaleDateString('fr-FR',{month:'long',year:'numeric'});
$('#h1').innerHTML=p=='dash'?`Aperçu Global <small>- ${m[0].toUpperCase()+m.slice(1)}</small>`:PAGES.find(x=>x[0]==p)[2];V[p]()}

/* ---------- timetable ---------- */
const DAYS=['Lun','Mar','Mer','Jeu','Ven'];
function tt(head){const o=head?1:0;
let h=`<div class="tt" style="grid-template-rows:${head?'auto ':''}repeat(10,32px)">`;
if(head)DAYS.forEach((d,i)=>h+=`<div style="grid-column:${i+2};grid-row:1;text-align:center;font-weight:600;padding:4px">${d}</div>`);
for(let i=0;i<10;i++)h+=`<div class="h" style="grid-row:${i+1+o}">${8+i}h</div>`;
D.sched.forEach((e,i)=>h+=`<div class="ev ${i==ei?'sel':''}" onclick="evClick(${i})" style="grid-column:${e.c};grid-row:${e.r+o}/span ${e.s};background:${esc(e.k)}"><b>${esc(e.t)}</b>${esc(e.g)}<br>Prof. ${esc(e.p)}</div>`);
return h+'</div>'}
function evClick(i){const e=D.sched[i];if(isAdmin()){ei=i;go('sch');scrollTo(0,0)}else toast(`${e.t} · ${e.g} · Prof. ${e.p} · ${7+e.r}h–${7+e.r+e.s}h`)}
function sform(){const e=ei>=0?D.sched[ei]:{c:2,r:1,s:1,t:'',g:'',p:'',k:'#2f6fe4'};
const sel=(id,arr,cur)=>`<select id="${id}">${arr.map(([v,l])=>`<option value="${v}" ${v==cur?'selected':''}>${l}</option>`).join('')}</select>`;
return `<div class="card" style="margin-bottom:16px"><h2>${ei>=0?'Modifier le cours':'Ajouter un cours'}</h2><div class="form">
${sel('sd',DAYS.map((d,i)=>[i+2,d]),e.c)}${sel('sh',[...Array(10)].map((_,i)=>[i+1,'Début '+(8+i)+'h']),e.r)}${sel('sl',[1,2,3,4].map(n=>[n,'Durée '+n+'h']),e.s)}
<input id="st" placeholder="Matière" value="${esc(e.t)}"><input id="sg" placeholder="Groupe" value="${esc(e.g)}"><input id="sp" placeholder="Prof" value="${esc(e.p)}">
<input id="sk" type="color" value="${esc(e.k)}" style="padding:2px;width:44px">
<button class="btn" onclick="saveEv()">${ei>=0?'Enregistrer':'+ Ajouter'}</button>${ei>=0?'<button class="btn g" onclick="ei=-1;V.sch()">Annuler</button><button class="btn r" onclick="delEv()">Supprimer</button>':''}</div></div>`}
function saveEv(){if(!isAdmin())return;const c=+$('#sd').value,r=+$('#sh').value,s=+$('#sl').value,t=$('#st').value.trim(),g=$('#sg').value.trim(),p=$('#sp').value.trim();
if(!t||!g||!p)return toast('Remplissez matière, groupe et prof');
if(r+s-1>10)return toast('Le cours dépasse 18h');
if(D.sched.some((x,i)=>i!=ei&&x.c==c&&r<x.r+x.s&&x.r<r+s))return toast('Chevauchement : créneau déjà occupé');
const o={c,r,s,t,g,p,k:$('#sk').value},id=ei>=0?D.sched[ei].id:0;act(async()=>{id?await api('PUT','/api/sched/'+id,o):await api('POST','/api/sched',o);ei=-1},'Emploi du temps mis à jour ✓')}
function delEv(){if(!isAdmin()||ei<0)return;const id=D.sched[ei].id;act(async()=>{await api('DELETE','/api/sched/'+id);ei=-1},'Cours supprimé')}

/* ---------- generic lists ---------- */
const F={stu:['students',[['n','Nom complet'],['g','Groupe'],['l','Niveau']]],tea:['teachers',[['n','Nom'],['m','Matière']]],grp:['groups',[['n','Nom du groupe'],['l','Niveau'],['p','Prof'],['c','Étudiants',1]]],pay:['pay',[['n','Nom'],['g','Groupe'],['a','Montant (DH)',1]]]};
function list(p){const[k,f]=F[p],A=isAdmin();
const rows=D[k].map((x,i)=>[x,i]).filter(([x])=>JSON.stringify(x).toLowerCase().includes(q));
$('#view').innerHTML=`<div class="card"><div class="form"><input id="q" placeholder="Rechercher…" value="${esc(q)}">${f.map(([a,l,n])=>`<input id="f_${a}" placeholder="${l}" ${n?'type="number" min="0"':''}>`).join('')}<button class="btn" id="add">+ Ajouter</button></div>
<div class="wrap"><table><tr>${f.map(x=>`<th>${x[1]}</th>`).join('')}${p=='pay'?'<th>Statut</th>':''}<th></th></tr>
${rows.map(([x,i])=>`<tr>${f.map(([a])=>`<td>${p=='pay'&&a=='a'?fmt(x.a):esc(x[a])}</td>`).join('')}${p=='pay'?`<td><span class="${x.s=='Payé'?'ok':'tag'}">${x.s}</span></td>`:''}
<td>${A&&p=='pay'&&x.s!='Payé'?`<button class="btn g" onclick="pay(${i})">Encaisser</button> `:''}${A?`<button class="btn r" onclick="del('${k}',${i})">Supprimer</button>`:''}</td></tr>`).join('')||'<tr><td colspan="9" class="mu">Aucun résultat.</td></tr>'}</table></div></div>`;
$('#q').oninput=e=>{q=e.target.value.toLowerCase();list(p);const n=$('#q');n.focus();n.setSelectionRange(99,99)};
$('#add').onclick=()=>{const o={};for(const[a,,n]of f){const v=$('#f_'+a).value.trim();if(!v)return toast('Remplissez tous les champs');o[a]=n?+v:v}
act(()=>api('POST','/api/'+k,o),'Ajouté ✓')}}
function del(k,i){if(!isAdmin())return toast('Réservé aux admins');act(()=>api('DELETE',`/api/${k}/${D[k][i].id}`),'Supprimé')}
function pay(i){if(!isAdmin())return toast('Réservé aux admins');act(()=>api('POST',`/api/pay/${D.pay[i].id}/paid`),'Paiement encaissé ✓')}
const dismiss=i=>act(()=>api('DELETE','/api/alerts/'+D.alerts[i].id));

/* ---------- views ---------- */
const V={
dash(){const A=isAdmin(),late=D.pay.map((x,i)=>[x,i]).filter(([x])=>x.s=='Retard');let off=25;
$('#view').innerHTML=`<section class="kpis">
<div class="card kpi"><div class="ic">👥</div><div><p>Étudiants Actifs</p><b>${D.students.length}</b></div></div>
<div class="card kpi"><div class="ic">💳</div><div><p>Paiements ce Mois</p><b>${fmt(total())}</b></div></div>
<div class="card kpi"><div class="ic">🏫</div><div><p>Taux d'Occupation Salles</p><b>${occ()}%</b></div></div>
<div class="card kpi"><div class="ic">🧑‍🏫</div><div><p>Profs en Cours</p><b>${D.teachers.length}</b></div></div></section>
<section class="g2"><div class="card"><h2>Emploi du Temps</h2>${tt(1)}</div><div class="col">
<div class="card"><h2>Prochains Paiements en Retard</h2><div class="wrap"><table><tr><th>Nom</th><th>Groupe</th><th>Montant</th><th></th></tr>
${late.map(([x,i])=>`<tr><td>${esc(x.n)}</td><td>${esc(x.g)}</td><td>${fmt(x.a)}</td><td>${A?`<button class="btn g" onclick="pay(${i})">Encaisser</button>`:`<span class="tag">Retard</span>`}</td></tr>`).join('')||'<tr><td colspan="4" class="mu">Aucun retard 🎉</td></tr>'}</table></div></div>
<div class="card"><h2>Derniers Groupes Créés</h2><div class="wrap"><table><tr><th>Groupe</th><th>Niveau</th><th>Prof</th><th>Étudiants</th></tr>
${D.groups.slice(0,3).map(x=>`<tr><td>${esc(x.n)}</td><td>${esc(x.l)}</td><td>${esc(x.p)}</td><td>${x.c}</td></tr>`).join('')}</table></div></div></div></section>
<section class="g3"><div class="card"><h2>Étudiants par Niveau</h2><div class="donut"><svg width="150" height="150" viewBox="0 0 42 42">
${seg().map(([n,v,c,m])=>{const r=`<circle cx="21" cy="21" r="15.9" fill="none" stroke="${c}" stroke-width="6" stroke-dasharray="${v} ${100-v}" stroke-dashoffset="${off}" data-t="${esc(n)} : ${v}% — ${m} étudiant(s)" onclick="toast(this.dataset.t)"/>`;off-=v;return r}).join('')}</svg>
<div class="lg">${seg().map(([n,v,c])=>`<div><i style="background:${c}"></i>${esc(n)} ${v}%</div>`).join('')}</div></div></div>
<div class="card"><h2>Alertes &amp; Notifications (${D.alerts.length})</h2><ul class="al">${D.alerts.map((a,i)=>`<li onclick="dismiss(${i})" title="Fermer"><span class="dot" style="background:${a.c}"></span><span style="flex:1">${esc(a.t)}</span><span class="mu">✕</span></li>`).join('')||'<li class="mu">Tout est à jour ✓</li>'}</ul></div></section>`},
stu:()=>list('stu'),tea:()=>list('tea'),grp:()=>list('grp'),pay:()=>list('pay'),
sch(){const A=isAdmin();$('#view').innerHTML=(A?sform():'')+`<div class="card"><h2>Semaine — ${A?'cliquez sur un cours pour le modifier':'consultation seule'}</h2>${tt(1)}</div>`},
rep(){$('#view').innerHTML=`<div class="card"><h2>Total encaissé — ${fmt(total())}</h2>${seg().map(([n,v,c,m])=>`<p>${esc(n)} · ${m} étudiant(s) (${v}%)</p><div class="bar" style="width:${v}%;background:${c}"></div>`).join('')}</div>`},
async usr(){try{USERS=(await api('GET','/api/users')).map(x=>({id:x.id,u:x.username,r:x.role}))}catch(e){return toast(e.message)}$('#view').innerHTML=`<div class="card"><h2>Ajouter un utilisateur</h2><div class="form"><input id="nu" placeholder="Nom d'utilisateur"><input id="npw" type="password" placeholder="Mot de passe (6+)"><select id="nr"><option value="staff">Autre (staff)</option><option value="admin">Admin</option></select><button class="btn" onclick="addUser()">+ Ajouter</button></div>
<h2>Utilisateurs (${USERS.length})</h2><div class="wrap"><table><tr><th>Utilisateur</th><th>Rôle</th><th></th></tr>${USERS.map((x,i)=>`<tr><td>${esc(x.u)}${x.u==ME.u?' (vous)':''}</td><td><select onchange="setRole(${i},this.value)"><option value="admin" ${x.r=='admin'?'selected':''}>Admin</option><option value="staff" ${x.r!='admin'?'selected':''}>Autre (staff)</option></select></td><td>${x.u==ME.u?'':`<button class="btn r" onclick="delUser(${i})">Supprimer</button>`}</td></tr>`).join('')}</table></div></div>`},
set(){$('#view').innerHTML=`<div class="card"><h2>Apparence</h2><div class="form"><button class="btn g" onclick="setTheme('light')">Clair</button><button class="btn g" onclick="setTheme('dark')">Sombre</button><button class="btn g" onclick="setTheme('')">Auto</button></div>
<h2>Sécurité</h2><div class="form"><input id="np" type="password" placeholder="Nouveau mot de passe (6+)"><button class="btn g" onclick="chpw()">Changer</button></div>
</div>`}};

/* ---------- auth ---------- */
const nameOk=u=>/^[\w.-]{3,20}$/.test(u);
const err=m=>{$('#le').textContent=m;const b=$('#lbox');b.classList.remove('shake');void b.offsetWidth;b.classList.add('shake')};
let signupMode=false;
function mode(su){signupMode=su;$('#lc').hidden=!su;$('#le').textContent='';
$('#lt').textContent=su?'Créer un compte':'Noble Center';$('#ls').textContent=su?'Compte « Autre » : accès limité (un admin peut changer votre rôle)':"Connectez-vous à l'espace administration";
$('#lb').textContent=su?"S'inscrire":'Se connecter';
$('#lw').innerHTML=su?'Déjà un compte ? <a href="#" id="sw">Se connecter</a>':'Pas de compte ? <a href="#" id="sw">Créer un compte</a>';
$('#sw').onclick=e=>{e.preventDefault();mode(!signupMode)}}
async function submit(){const b=$('#lb'),t=b.textContent;b.disabled=true;b.textContent='…';
try{const u=$('#lu').value.trim(),p=$('#lp').value;
if(signupMode){if(!nameOk(u))return err("Nom d'utilisateur : 3-20 caractères (lettres, chiffres, . _ -)");if(p.length<6)return err('Mot de passe : 6 caractères minimum');if(p!=$('#lc').value)return err('Les mots de passe ne correspondent pas')}
const m=await api('POST',signupMode?'/api/signup':'/api/login',{username:u,password:p});ME={u:m.username,r:m.role};await load();show();signupMode&&toast('Bienvenue '+u+' 👋')}
catch(e){err(e.message)}finally{b.disabled=false;b.textContent=t}}
function logout(){api('POST','/api/logout').catch(()=>{}).finally(()=>{ME=null;show()})}
async function chpw(){const v=$('#np').value;if(v.length<6)return toast('Minimum 6 caractères');try{await api('POST','/api/password',{password:v});$('#np').value='';toast('Mot de passe modifié ✓')}catch(e){toast(e.message)}}
async function uact(f,ok){try{await f();toast(ok);const m=await api('GET','/api/me');const was=ME.r;ME={u:m.username,r:m.role};ME.r!=was?show():V.usr()}catch(e){toast(e.message);V.usr()}}
const setRole=(i,r)=>uact(()=>api('PUT','/api/users/'+USERS[i].id,{role:r}),'Rôle modifié ✓');
const delUser=i=>uact(()=>api('DELETE','/api/users/'+USERS[i].id),'Utilisateur supprimé');
const addUser=()=>uact(()=>api('POST','/api/users',{username:$('#nu').value.trim(),password:$('#npw').value,role:$('#nr').value}),'Utilisateur ajouté ✓');
/* ---------- synchro temps réel ---------- */
let ver=null,pt=0,pend=false,tmr=0;
const busy=()=>/INPUT|SELECT/.test(document.activeElement.tagName)||[...document.querySelectorAll('#view input:not(#q)')].some(i=>i.value&&i.type!='color');
async function refresh(){if(!ME)return;if(busy()){pend=true;return}pend=false;try{await load();V[page]()}catch(e){}}
const later=()=>{clearTimeout(tmr);tmr=setTimeout(refresh,150)};
function live(){clearInterval(pt);ver=null;if(!ME)return;const tick=async()=>{if(document.hidden)return;try{const v=(await api('GET','/api/ver')).ver;if(ver!==null&&v!==ver)later();ver=v}catch(e){}};tick();pt=setInterval(tick,2000)}
document.addEventListener('focusout',()=>{if(pend)setTimeout(refresh,200)});
document.addEventListener('visibilitychange',()=>{if(!document.hidden)later()});
addEventListener('online',later);
function show(){live();$('#login').hidden=!!ME;$('#app').hidden=!ME;
if(!ME){['lu','lp','lc'].forEach(i=>$('#'+i).value='');return mode(false)}
$('#uname').textContent=ME.u+' · '+(isAdmin()?'Admin':'Autre');applyTheme();go('dash')}

/* ---------- start ---------- */
async function init(){$('#lb').onclick=submit;['lu','lp','lc'].forEach(i=>$('#'+i).onkeydown=e=>{if(e.key=='Enter')submit()});
try{const m=await api('GET','/api/me');ME={u:m.username,r:m.role};await load()}catch(e){ME=null}
$('#boot').hidden=true;applyTheme();show();
setInterval(later,60000)}
init();
</script></body></html>
