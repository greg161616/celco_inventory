// Run with Node 22+ and CELCO_TEST_FIXTURE from tests/fixture.ps1.
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, promises as fs } from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const manifest = process.env.CELCO_TEST_FIXTURE;
if (!manifest) throw new Error('Set CELCO_TEST_FIXTURE to the isolated fixture manifest.');
const php = process.env.CELCO_QA_PHP || 'php';
const browser = process.env.CELCO_QA_BROWSER ||
    path.join(process.env['ProgramFiles(x86)'] || 'C:/Program Files (x86)', 'Microsoft/Edge/Application/msedge.exe');
if (!existsSync(browser)) throw new Error('Set CELCO_QA_BROWSER to an installed Chromium or Edge executable.');
const tempRoot = await fs.realpath(os.tmpdir());
const temp = await fs.mkdtemp(path.join(tempRoot,'celco-browser-'));
const site = path.join(temp,'site');
const profile = path.join(temp,'profile');
const username = 'browser-' + randomBytes(5).toString('hex');
const password = randomBytes(18).toString('hex');
const children = [];
let client;
let browserControl;
const failures = [];
const checked = (result, label) => {
    if (result) console.log('PASS ' + label);
    else {
        console.error('FAIL ' + label);
        failures.push(label);
    }
};
async function until(work, label, timeout = 12000) {
    const deadline = Date.now() + timeout;
    let reason;
    do {
        try { const result = await work(); if (result) return result; }
        catch (error) { reason = error.message; }
        await delay(80);
    } while (Date.now() < deadline);
    throw new Error(label + (reason ? ' (' + reason + ')' : ''));
}
function start(program, args) {
    const p = spawn(program, args, {windowsHide:true, stdio:'ignore', env:process.env});
    children.push(p);
    return p;
}
async function freePort() {
    const socket = net.createServer();
    await new Promise((resolve,reject)=>socket.once('error',reject).listen(0,'127.0.0.1',resolve));
    const port = socket.address().port;
    await new Promise(resolve=>socket.close(resolve));
    return port;
}
async function devtools(url, onEvent=()=>{}) {
    const socket = new WebSocket(url);
    await new Promise((resolve,reject) => {
        socket.addEventListener('open',resolve,{once:true});
        socket.addEventListener('error',reject,{once:true});
    });
    let seq = 0;
    const calls = new Map();
    socket.addEventListener('message', event => {
        const data = JSON.parse(event.data);
        if (data.id && calls.has(data.id)) {
            const {resolve,reject,timer} = calls.get(data.id);
            clearTimeout(timer);
            calls.delete(data.id);
            if (data.error) reject(new Error(data.error.message));
            else resolve(data.result);
        } else if (data.method) onEvent(data);
    });
    return {
        send(method, params={}) {
            const id=++seq;
            return new Promise((resolve,reject)=>{
                const timer=setTimeout(()=>{calls.delete(id);reject(new Error('DevTools timed out on '+method));},15000);
                calls.set(id,{resolve,reject,timer});
                socket.send(JSON.stringify({id,method,params}));
            });
        },
        close() { socket.close(); },
    };
}

try {
    const seed = spawnSync(php, [path.join(root,'tests/browser_fixture.php')], {
        cwd:root, encoding:'utf8', windowsHide:true,
        env:{...process.env,CELCO_QA_USER:username,CELCO_QA_PASSWORD:password},
    });
    if (seed.status !== 0) throw new Error('Fixture setup failed. ' + seed.stderr);
    const ids = JSON.parse(seed.stdout);
    await fs.mkdir(site);
    await Promise.all(['api','assets','auth','includes','layouts','modules'].map(name=>
        fs.cp(path.join(root,name),path.join(site,name),{recursive:true})));
    await Promise.all(['config','vendor','storage/logs','sessions'].map(name=>fs.mkdir(path.join(site,name),{recursive:true})));
    await fs.copyFile(path.join(root,'config/app.php'),path.join(site,'config/app.php'));
    await fs.copyFile(path.join(root,'index.php'),path.join(site,'index.php'));
    await fs.writeFile(path.join(site,'vendor/autoload.php'),"<?php require " + JSON.stringify(path.join(root,'vendor/autoload.php').replaceAll('\\','/')) + ";");
    // The temporary web process only receives the guarded fixture DSN. Never copy config/db.php or .env.
    await fs.writeFile(path.join(site,'config/db.php'), `<?php
$fixture = json_decode(file_get_contents(getenv('CELCO_TEST_FIXTURE')),true,flags:JSON_THROW_ON_ERROR);
$dir = realpath($fixture['directory']);
if (!$dir || strcasecmp(dirname($dir),realpath(sys_get_temp_dir())) !== 0 ||
    !preg_match('/^celco-qa-[a-f0-9]{32}$/D',basename($dir)) ||
    realpath(getenv('CELCO_TEST_FIXTURE')) !== realpath($dir . '/fixture.json') ||
    $fixture['database'] !== 'celco_ims_test' || $fixture['host'] !== '127.0.0.1' ||
    !is_int($fixture['port']) || $fixture['port'] < 1024 || $fixture['port'] > 65535 || $fixture['port'] === 3306) {
    throw new RuntimeException('Invalid test fixture.');
}
$pdo = new PDO('mysql:host=127.0.0.1;port='.$fixture['port'].';charset=utf8mb4',$fixture['user'],$fixture['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
if (strcasecmp(realpath($pdo->query('SELECT @@datadir')->fetchColumn()),realpath($dir.'/data')) !== 0) {
    throw new RuntimeException('Incorrect test server.');
}
$pdo->exec('USE celco_ims_test');
unset($fixture);
`);
    const port = await freePort();
    const base = 'http://127.0.0.1:' + port + '/';
    start(php,['-d','session.save_path='+path.join(site,'sessions'),'-S','127.0.0.1:'+port,'-t',site]);
    await until(async()=> (await fetch(base+'auth/login.php')).ok,'PHP did not start');
    start(browser,['--headless=new','--no-first-run','--disable-background-networking','--disable-sync',
        '--remote-debugging-port=0','--remote-debugging-address=127.0.0.1','--user-data-dir='+profile,
        '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE localhost, EXCLUDE 127.0.0.1','about:blank']);
    const debuggerPort = await until(async()=>{
        const info=await fs.readFile(path.join(profile,'DevToolsActivePort'),'utf8');
        return Number(info.split(/\r?\n/)[0]);
    },'The browser did not open its local DevTools port',25000);
    const debug='http://127.0.0.1:'+debuggerPort;
    const version=await (await fetch(debug+'/json/version')).json();
    browserControl=await devtools(version.webSocketDebuggerUrl);
    const targets=await (await fetch(debug+'/json/list')).json();
    const tab=targets.find(page=>page.type==='page' && page.url==='about:blank');
    const issues=[];
    const responses=new Map();
    client=await devtools(tab.webSocketDebuggerUrl, ({method,params})=>{
        if (method==='Runtime.exceptionThrown') {
            const d=params.exceptionDetails;
            issues.push('JavaScript exception in '+(d.url || '(document)')+' line '+(d.lineNumber+1)+': '+(d.exception?.description || d.text));
        }
        if (method==='Network.responseReceived' && params.response.url.startsWith(base)) {
            const {url,status}=params.response;
            responses.set(url,status);
            if (status>=400) issues.push('HTTP '+status+' '+url.slice(base.length));
        }
    });
    await Promise.all(['Page.enable','Runtime.enable','Network.enable'].map(m=>client.send(m)));
    const evaluate=async expression=>{
        const r=await client.send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
        if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
        return r.result.value;
    };
    const viewport=(width,height)=>client.send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
    const navigate=async relative=>{
        const url=base+relative;
        await client.send('Page.navigate',{url});
        await until(()=>evaluate('location.href==='+JSON.stringify(url)+' && document.readyState==="complete"'),'Page failed to load '+relative);
        return (await evaluate('({heading:document.querySelector("main h1")?.textContent ?? "", width:innerWidth, content:document.documentElement.scrollWidth, workspace:!!window.CelcoUI && !!document.querySelector("#main-content")})'));
    };
    await viewport(1366,900);
    await navigate('auth/login.php');
    await evaluate('document.querySelector("[name=username]").value='+JSON.stringify(username)+';document.querySelector("[name=password]").value='+JSON.stringify(password)+';document.querySelector("form").requestSubmit();true');
    await until(()=>evaluate('location.pathname.includes("/dashboard/") && document.readyState==="complete" && !!window.CelcoUI'),'Sign-in did not reach the dashboard');
    const dashboard='modules/dashboard/index.php';
    for (const width of [1366,390]) {
        await viewport(width,width===390?844:900);
        const dimensions=await navigate(dashboard);
        checked(dimensions.workspace && dimensions.content<=width+1, width+'px authenticated dashboard ('+dimensions.content+'px document)');
        checked(await evaluate('typeof Chart==="function" && [...document.querySelectorAll("canvas[data-chart]")].length>0 && [...document.querySelectorAll("canvas[data-chart]")].every(c=>!!Chart.getChart(c))'), width+'px populated Chart.js charts');
        if (width===390) {
            await evaluate('document.querySelector("[data-menu-toggle]").click()');
            checked(await evaluate('document.body.classList.contains("menu-open") && !document.querySelector(".sidebar-overlay").hidden && document.querySelector("[data-menu-toggle]").getAttribute("aria-expanded")==="true"'),'mobile navigation opens');
            await evaluate('document.querySelector("#workspace-menu [data-menu-close]").click()');
            checked(await evaluate('!document.body.classList.contains("menu-open") && document.querySelector(".sidebar-overlay").hidden && document.activeElement.matches("[data-menu-toggle]")'),'mobile navigation closes and restores focus');
        }
        await evaluate('document.querySelector("[data-dashboard-filter] select[name=warehouse_id]").value='+JSON.stringify(String(ids.warehouse))+';document.querySelector("[data-dashboard-filter]").requestSubmit();true');
        await until(()=>evaluate('location.search.includes("warehouse_id='+ids.warehouse+'") && !document.querySelector("#dashboard-results").hasAttribute("aria-busy")'),'Dashboard Fetch failed at '+width+'px');
        checked([...responses].some(([url,status])=>url.includes('/api/dashboard.php?') && status===200),width+'px dashboard Fetch response and DOM refresh');
    }
    await navigate('modules/stock-in/index.php?type=RECEIPT&new=1');
    const code='WIRE-'+username.slice(8);
    await evaluate('const search=document.querySelector("[data-material-search]");search.value='+JSON.stringify(code)+';search.dispatchEvent(new Event("input",{bubbles:true}));true');
    await until(()=>evaluate('!!Array.from(document.querySelector("[name=\'lines[0][material_id]\']").options).find(o=>o.textContent.includes('+JSON.stringify(code)+'))'),'Receipt material search did not find the fixture material');
    await evaluate('const form=document.querySelector("[data-lines-form]");form.elements["lines[0][material_id]"].value='+JSON.stringify(String(ids.material))+
        ';form.elements["lines[0][quantity]"].value="6";form.elements["lines[0][to_warehouse_id]"].value='+JSON.stringify(String(ids.warehouse))+
        ';form.querySelector("button[value=create]").click();true');
    await until(()=>evaluate('location.search.includes("id=") && document.readyState==="complete" && document.querySelector("article.panel")?.innerText.includes("DRAFT")'),'Receipt draft was not created');
    const receiptDetail=await evaluate('location.pathname.slice(1)+location.search');
    const editLink=await evaluate('Array.from(document.querySelectorAll("main a")).find(a=>a.textContent==="Edit draft")?.href');
    if (!editLink?.startsWith(base)) throw new Error('Draft editing link is missing');
    await navigate(editLink.slice(base.length));
    checked(await evaluate('document.querySelector("[data-lines-form]").elements["lines[0][quantity]"].value==="6.000"'),'draft edit form restores the saved line quantity');
    await evaluate('const editForm=document.querySelector("[data-lines-form]"); editForm.elements["lines[0][quantity]"].value="7.500";editForm.querySelector("button[value=edit]").click();true');
    await until(()=>evaluate('!location.search.includes("edit=") && document.readyState==="complete" && document.querySelector("main table")?.innerText.includes("7.500")'),'Receipt draft update was not saved');
    checked(await evaluate('location.pathname.slice(1)+location.search')===receiptDetail,'draft edit retains the original document');
    await evaluate('document.querySelector("main button[value=post]").click()');
    await until(()=>evaluate('!!document.querySelector(".swal2-confirm")'),'Receipt post confirmation was not shown');
    await evaluate('document.querySelector(".swal2-confirm").click()');
    await until(()=>evaluate('document.readyState==="complete" && document.querySelector("article.panel")?.innerText.includes("POSTED")'),'Receipt posting did not complete');
    checked(await evaluate('location.pathname.slice(1)+location.search')===receiptDetail,'same receipt reached posted status');
    await navigate('modules/materials/index.php?id='+ids.material);
    checked(await evaluate('document.querySelector("main").innerText.includes("107.500")'),'posted quantity updates the displayed warehouse balance');
    const modules=(await fs.readdir(path.join(site,'modules'),{withFileTypes:true})).filter(v=>v.isDirectory()).map(v=>v.name);
    const variants={
        materials:['?edit=1','?id='+ids.material,'?id='+ids.material+'&edit=1'],
        categories:['?edit=1','?id='+ids.category],warehouses:['?id='+ids.warehouse],departments:['?id='+ids.department],
        'stock-in':['?type=RECEIPT&new=1','?type=RECEIPT&id='+ids.receipt],
        'stock-out':['?new=1'],returns:['?new=1'],transfers:['?new=1'],adjustments:['?new=1'],disposals:['?new=1'],
        requisitions:['?new=1','?id='+ids.request],'stock-counts':['?id='+ids.count,'?id='+ids.count+'&print=1'],
        users:['?new=1','?id='+ids.actor],roles:['?new=1','?id='+ids.role],
    };
    const routes=modules.flatMap(name=>['',...(variants[name]||[])].map(part=>'modules/'+name+'/index.php'+part));
    for (const width of [390,1366]) {
        await viewport(width,width===390?844:900);
        let good=0;
        for (const route of routes) {
            const result=await navigate(route);
            if (!result.workspace || responses.get(base+route)!==200 || result.content>width+1) {
                checked(false,width+'px '+route+' ('+result.heading+', '+result.content+'px, HTTP '+responses.get(base+route)+')');
            } else good++;
        }
        checked(good===routes.length,width+'px '+good+'/'+routes.length+' module list, form and detail pages');
    }
    checked([...responses].some(([url,status])=>url.endsWith('/api/notifications.php') && status===200),'notification bell Fetch returns 200');
    for (const issue of new Set(issues)) checked(false,issue);
    if (failures.length) throw new Error(failures.length+' browser checks failed');
} finally {
    if (browserControl) await browserControl.send('Browser.close').catch(()=>{});
    if (client) client.close();
    if (browserControl) browserControl.close();
    for (const child of children.reverse()) {
        if (child.exitCode===null) child.kill();
    }
    const resolved=await fs.realpath(temp);
    if (path.dirname(resolved)===tempRoot && path.basename(resolved).startsWith('celco-browser-')) {
        await fs.rm(resolved,{recursive:true,force:true,maxRetries:12,retryDelay:180});
    }
}
