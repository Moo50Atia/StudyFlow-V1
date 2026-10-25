#!/usr/bin/env python3
"""Render the existing local product UI in Chromium using temporary sessions.

Requires Python Playwright and its Chromium binary. Uses Docker to create and
remove sessions for existing accounts; never prints session cookies. Follows
navigation links with up to two records per URL shape. Blocks non-read HTTP
methods and skips downloads/logout. Missing roles are reported as skipped.
"""
import subprocess,json,re,argparse
from pathlib import Path
from urllib.parse import urlsplit
from collections import deque
from playwright.sync_api import sync_playwright
php=r'''require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$session=app('session')->driver();$session->start();$owner=App\Models\User::where('email','admin@test.com')->firstOrFail();
$guard=auth()->guard('web');$guard->login($owner);$session->put('frontend_render_audit',true);$session->save();
$name=config('session.cookie');$prefix=Illuminate\Cookie\CookieValuePrefix::create($name,app('encrypter')->getKey());
echo json_encode(['name'=>$name,'value'=>app('encrypter')->encrypt($prefix.$session->getId(),false),'session_id'=>$session->getId()]);'''
php=php.replace(chr(92)*2,chr(92))

parser=argparse.ArgumentParser()
parser.add_argument('--local-assets',action='store_true',help='Test local production build before deployment')
parser.add_argument('--base-url',default='http://127.0.0.1:8766')
parser.add_argument('--container',default='eduplatform-app-1')
parser.add_argument('--run-id',required=True,help='An existing run whose owning teacher should be tested')
parser.add_argument('--owner-email',default='admin@test.com')
parser.add_argument('--roles',nargs='+',choices=['admin','teacher','student','guest'],default=['admin','teacher','student','guest'])
parser.add_argument('--report',default='/tmp/frontend-render-audit.json')
args=parser.parse_args()
import uuid
uuid.UUID(args.run_id)
base=args.base_url.rstrip('/')
php=php.replace("'admin@test.com'", json.dumps(args.owner_email))
root=Path(__file__).resolve().parents[2]
results=[]
with sync_playwright() as p:
 browser=p.chromium.launch(headless=True)
 for role in args.roles:
  actor_php=php
  if role=='teacher':
   start=actor_php.index('$owner='); end=actor_php.index(';',start)
   actor_php=actor_php[:start]+"$owner=App\\Models\\User::findOrFail(App\\Models\\V3\\SubjectRun::findOrFail('"+args.run_id+"')->owner_user_id)"+actor_php[end:]
  if role=='student':
   start=actor_php.index('$owner='); end=actor_php.index(';',start)
   actor_php=actor_php[:start]+"$owner=App\\Models\\User::where('role','student')->first();if(!$owner){echo json_encode(['skip'=>true]);return;}"+actor_php[end+1:]
  auth=None if role=='guest' else json.loads(subprocess.run(['docker','exec',args.container,'php','-r',actor_php],capture_output=True,text=True,check=True).stdout)
  if auth and auth.get('skip'):
   print(json.dumps({'role':role,'skipped':'No existing account'}),flush=True);continue
  context=browser.new_context(viewport={'width':1440,'height':1000})
  if auth: context.add_cookies([{'name':auth['name'],'value':auth['value'],'url':base}])
  if args.local_assets:
   manifest=json.loads((root/'public/build/manifest.json').read_text())
   def assets(route):
    path=urlsplit(route.request.url).path.removeprefix('/build/')
    file=root/'public/build'/path
    if file.is_file(): route.fulfill(path=str(file))
    else: route.continue_()
   def document(route):
    if route.request.resource_type != 'document':
     route.continue_();return
    try:
     response=route.fetch()
    except Exception:
     route.abort();return
    content=response.text()
    content=re.sub(r'/build/assets/app-[^"\s<>]+\.js', '/build/'+manifest['resources/js/app.jsx']['file'],content)
    content=re.sub(r'/build/assets/app-[^"\s<>]+\.css', '/build/'+manifest['resources/css/app.css']['file'],content)
    route.fulfill(response=response,body=content)
   context.route(base+'/**',document)
   context.route('**/build/**',assets)
  def watch_only(route):
   if route.request.method not in ['GET','HEAD','OPTIONS']:
    route.abort();return
   route.fallback()
  context.route('**/*',watch_only)
  page=context.new_page()
  errors=[]; network=[]
  page.on('pageerror',lambda error:errors.append(str(error)))
  page.on('response',lambda response:network.append({'status':response.status,'url':urlsplit(response.url).path}) if response.status>=400 else None)
  seeds=['/','/dashboard','/profile','/subjects','/lectures','/sections','/questions','/exam_questions']
  if role=='admin': seeds+=['/users','/teacher_permissions','/v3/admin','/v3/admin/workspace','/v3/admin/accounts','/v3/admin/cases','/v3/admin/devices','/v3/admin/evidence','/v3/admin/operations','/v3/admin/settings','/v3/admin/settings/history','/v3/admin/settings/templates','/v3/admin/subject-runs','/v3/teacher/runs/RUN_ID_PLACEHOLDER']
  elif role=='teacher': seeds+=['/v3/teacher','/v3/teacher/workspace','/v3/teacher/pdf-uploads','/v3/teacher/reviews','/v3/teacher/runs/RUN_ID_PLACEHOLDER']
  if role=='guest': seeds=['/','/login','/register','/forgot-password']
  seeds=[seed.replace('RUN_ID_PLACEHOLDER',args.run_id) for seed in seeds]
  pending=deque(seeds); visited=set(); shapes={}
  try:
   while pending and len(visited)<250:
    path=pending.popleft()
    if path in visited: continue
    shape=re.sub(r'/[0-9a-f]{8}-[0-9a-f-]{27,}(?=/|$)|/\d+(?=/|$)', '/{id}',urlsplit(path).path)
    if shapes.get(shape,0)>=2: continue
    shapes[shape]=shapes.get(shape,0)+1
    visited.add(path);errors.clear();network.clear()
    try:
     response=page.goto(base+path,wait_until='load',timeout=20000)
     if response and response.status==200 and page.locator('#app').count():
      page.wait_for_function("document.getElementById('app').childElementCount > 0",timeout=5000)
     page.wait_for_timeout(350)
     if urlsplit(page.url).path == '/v3/admin/subject-runs':
      page.get_by_placeholder('Search by run ID, origin, or mode...').fill('__browser_audit_no_match__')
      page.get_by_text('No subject runs found',exact=True).wait_for()
      page.get_by_placeholder('Search by run ID, origin, or mode...').fill('')
      if page.get_by_role('button',name='Cancel',exact=True).count():
       page.get_by_role('button',name='Cancel',exact=True).first.click()
       dialog=page.get_by_role('dialog')
       dialog.get_by_role('heading',name='Confirm Run CANCEL').wait_for()
       dialog.get_by_role('button',name='Cancel',exact=True).click()
      page.screenshot(path='/tmp/admin-runs-after.png',full_page=True)
     text=page.locator('body').inner_text()
     status=response.status if response else 0
     row={'role':role,'path':path,'status':status,'errors':errors.copy(),'network':network.copy(),'text_length':len(text),'heading_count':page.locator('h1').count(),'component':page.locator('script[data-page]').evaluate('(el)=>JSON.parse(el.textContent).component') if page.locator('script[data-page]').count() else None,'seed':path in seeds}
     row['expected_denial']=status==403 and role in ['teacher','student'] and path in ['/questions','/exam_questions']
     results.append(row)
     print(json.dumps(row),flush=True)
     if errors or not text.strip(): page.screenshot(path='/tmp/render-failure-'+role+'-'+str(len(visited))+'.png')
     if status>=400 or errors: continue
     for href in page.locator('a[href]').evaluate_all('(links)=>links.map(a=>a.getAttribute("href"))'):
      parsed=urlsplit(href)
      if parsed.netloc and parsed.netloc!=urlsplit(base).netloc: continue
      target=parsed.path
      if re.search(r'/(undefined|null|\[object%20Object\])(?:/|$)',target):
       row['errors'].append('Malformed navigation URL: '+target)
      if not target.startswith('/') or target.startswith(('/api/','/storage/','/owner-assets/','/scenes/')): continue
      if role=='guest' and target not in ['/', '/login', '/register', '/forgot-password']: continue
      if any(segment in target.split('/') for segment in ['logout','exit','download','export','frames','preview','file','files','artifacts','prompt-export']): continue
      if target not in visited: pending.append(target+('?' + parsed.query if parsed.query else ''))
    except Exception as error:
     row={'role':role,'path':path,'error':str(error).splitlines()[0]};results.append(row);print(json.dumps(row),flush=True)
  finally:
   context.close()
   cleanup="require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();app('session')->driver()->getHandler()->destroy($argv[1]);"
   if auth:
    cleaned=subprocess.run(['docker','exec',args.container,'php','-r',cleanup,auth['session_id']],capture_output=True,text=True)
    if cleaned.returncode: raise RuntimeError('Could not clean up temporary audit session; verify the application container is running.')
 browser.close()
Path(args.report).write_text(json.dumps(results,indent=2))
failures=[r for r in results if r.get('error') or r.get('errors') or not r.get('text_length') or r.get('status',0)>=500 or any(n['status']>=500 or n['url']!=urlsplit(r['path']).path or not r.get('expected_denial') for n in r.get('network',[])) or (r.get('status',0)>=400 and not r.get('expected_denial'))]
print(f'Checked {len(results)} rendered pages; {len(failures)} rendering failures. Report: {args.report}')
raise SystemExit(bool(failures))
