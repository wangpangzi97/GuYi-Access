"""Destructive only to an explicitly named *_test database. PHP and MySQL/MariaDB required."""
from pathlib import Path
import os,sys,json,subprocess,time,socket,secrets,hashlib,hmac,urllib.request,urllib.error,urllib.parse,re,concurrent.futures

ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BINARY','php')
assert os.environ.get('GUYI_TESTING')=='1' and os.environ['GUYI_DB_NAME'].endswith('_test')
A='a'*64; B='b'*64; MANAGEMENT='9'*64
env=os.environ.copy();env['GUYI_ALLOW_LOCAL_HTTP']='1'
env['GUYI_MANAGEMENT_CREDENTIALS']=json.dumps({'fixture':{'app_key':A,'secret':MANAGEMENT,'actions':['generate','ban'],'expires_at':int(time.time())+3600},'operator':{'app_key':A,'secret':'8'*64,'actions':['unban','kick','del_card'],'expires_at':int(time.time())+3600},'expired':{'app_key':A,'secret':'6'*64,'actions':['generate'],'expires_at':1}})

def cli(*args):
    p=subprocess.run([PHP,str(ROOT/'tests/fixture.php'),*args],env=env,capture_output=True,encoding='utf-8',errors='replace')
    if p.returncode:raise RuntimeError(p.stdout+p.stderr)
    return p.stdout

def hkdf(secret,purpose):
    prk=hmac.new(b'\0'*32,bytes.fromhex(secret),hashlib.sha256).digest()
    return hmac.new(prk,('guyi-v2/'+purpose).encode()+b'\x01',hashlib.sha256).digest()

def launch(no_crypto=False, overrides=None):
    s=socket.socket();s.bind(('127.0.0.1',0));port=s.getsockname()[1];s.close()
    cmd=[PHP]
    if no_crypto:
        cmd+=['-n','-d','extension_dir='+str(Path(PHP).parent/'ext'),'-d','extension=pdo_mysql','-d','extension=mbstring']
    log=open(ROOT/('test-server-'+str(port)+'.log'),'wb')
    server_env=env.copy();server_env.update(overrides or {})
    p=subprocess.Popen(cmd+['-S','127.0.0.1:'+str(port),'-t',str(ROOT)],env=server_env,stdout=log,stderr=log,creationflags=0x08000000 if os.name=='nt' else 0)
    for _ in range(200):
        try:
            with socket.create_connection(('127.0.0.1',port),timeout=.1):return p,port,log
        except OSError:time.sleep(.02)
    raise RuntimeError('PHP server failed')

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k):return None
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect)

def call(data=None, *, version=1, sid=None, secret=None, credential=None, nonce=None, timestamp=None, method='POST',path='/Verifyfile/api.php',raw=None,port=None,headers=None):
    body=raw if raw is not None else json.dumps(data,separators=(',',':')).encode()
    h={'Content-Type':'application/json','X-Timestamp':str(timestamp if timestamp is not None else int(time.time())),'X-Nonce':nonce or secrets.token_hex(16),'X-Key-Version':str(version)}
    if sid:h['X-Session-Id']=sid
    if credential:h['X-Credential-Id']=credential
    if secret:
        principal='management:'+credential if credential else 'session:'+sid
        c='\n'.join(['v2','POST',path,principal,h['X-Key-Version'],h['X-Timestamp'],h['X-Nonce'],hashlib.sha256(body).hexdigest()])
        h['X-Signature']=hmac.new(hkdf(secret,'request-sign'),c.encode(),hashlib.sha256).hexdigest()
    h.update(headers or {})
    req=urllib.request.Request('http://127.0.0.1:'+str(port or PORT)+path,data=body if method!='GET' else None,method=method,headers=h)
    try:r=opener.open(req,timeout=15)
    except urllib.error.HTTPError as e:r=e
    b=r.read().decode('utf-8');
    try:v=json.loads(b)
    except ValueError:v=b
    return r.status,v,r.headers

def expect(label,actual,expected):
    global count
    assert actual==expected,(label,actual,expected)
    count+=1;print('PASS',label,flush=True)

def decrypt(envelope,secret):
    p=subprocess.run([PHP,str(ROOT/'tests/decrypt.php')],input=json.dumps({'envelope':envelope,'secret':secret}),env=env,capture_output=True,encoding='utf-8')
    return p.returncode,json.loads(p.stdout or 'null')

count=0;servers=[]
try:
    print(cli('seed').strip());servers.append(launch());PORT=servers[0][1]
    basic={'action':'activate','app_key':A,'card_code':'TEST-YEAR-CARD','device_hash':'device-1'}
    expect('GET rejected',call(basic,method='GET')[0],405)
    expect('invalid JSON',call(raw=b'{bad')[0],400)
    expect('array field rejected',call(dict(basic,app_key=[]))[0],400)
    expect('expired timestamp',call(basic,timestamp=int(time.time())-301)[0],401)
    expect('future timestamp',call(basic,timestamp=int(time.time())+301)[0],401)
    expect('no magic card',call(dict(basic,card_code='156440000'))[0],403)
    expect('unauthorized unbind',call(dict(basic,action='unbind'))[0],401)
    expect('client cannot blacklist',call(dict(basic,action='ban_machine'))[0],403)
    expect('old global token rejected',call(dict(basic,action='generate',type='day',api_token='GuYiAdmin123'))[0],403)
    status,config,_=call({'action':'config','app_key':A})
    expect('public config available',status,200);expect('private variable excluded',config['data']['variables'],{'public':'notice'})
    expect('disabled app blocked',call({'action':'config','app_key':'e'*64})[0],403)
    status,activated,_=call(basic);expect('valid activation',status,200)
    session=activated['data'];sid=session['session_id'];secret=session['session_key']
    expect('app secret not sent', 'c'*64 in json.dumps(activated),False)
    heartbeat={'action':'heartbeat','app_key':A,'device_hash':'device-1'}
    nonce=secrets.token_hex(16);ts=int(time.time())
    status,envelope,_=call(heartbeat,sid=sid,secret=secret,nonce=nonce,timestamp=ts)
    expect('signed heartbeat',status,200);rc,plain=decrypt(envelope,secret);expect('AES authenticated response',rc,0);expect('licensed variable',plain['data']['variables']['private'],'licensed-only')
    expect('app_key cannot decrypt',decrypt(envelope,A)[0],2)
    expect('replay rejected',call(heartbeat,sid=sid,secret=secret,nonce=nonce,timestamp=ts)[0],409)
    expect('body/signature tamper',call(heartbeat,sid=sid,secret='8'*64)[0],401)
    expect('device mismatch',call(dict(heartbeat,device_hash='device-2'),sid=sid,secret=secret)[0],401)
    expect('app scope mismatch',call(dict(heartbeat,app_key=B),sid=sid,secret=secret)[0],401)
    gen={'action':'generate','app_key':A,'type':'day','num':2}
    status,body,_=call(gen,credential='fixture',secret=MANAGEMENT);expect('scoped management generation',status,200);expect('generated count',len(decrypt(body,'c'*64)[1]['data']['cards']),2)
    expect('management cross-app denied',call(dict(gen,app_key=B),credential='fixture',secret=MANAGEMENT)[0],403)
    expect('management missing permission',call(dict(gen,action='kick',card_code='TEST-YEAR-CARD'),credential='fixture',secret=MANAGEMENT)[0],403)
    expect('generation upper bound',call(dict(gen,num=1001),credential='fixture',secret=MANAGEMENT)[0],400)
    expect('expired management credential',call(gen,credential='expired',secret='6'*64)[0],403)
    expect('management cross-tenant card denied',call({'action':'ban','app_key':A,'card_code':'OTHER-CARD'},credential='fixture',secret=MANAGEMENT)[0],404)
    temp_card=decrypt(body,'c'*64)[1]['data']['cards'][0]
    expect('authorized ban',call({'action':'ban','app_key':A,'card_code':temp_card},credential='fixture',secret=MANAGEMENT)[0],200)
    expect('banned card rejected',call(dict(basic,card_code=temp_card))[0],403)
    expect('authorized unban',call({'action':'unban','app_key':A,'card_code':temp_card},credential='operator',secret='8'*64)[0],200)
    expect('authorized kick',call({'action':'kick','app_key':A,'card_code':temp_card},credential='operator',secret='8'*64)[0],200)
    expect('authorized deletion',call({'action':'del_card','app_key':A,'card_code':temp_card},credential='operator',secret='8'*64)[0],200)
    servers.append(launch());nonce=secrets.token_hex(16);ts=int(time.time())
    with concurrent.futures.ThreadPoolExecutor() as pool:
        codes=list(pool.map(lambda port:call(heartbeat,sid=sid,secret=secret,nonce=nonce,timestamp=ts,port=port)[0],[PORT,servers[1][1]]))
    expect('cross-worker atomic nonce',sorted(codes),[200,409])
    before=json.loads(cli('card'))
    expect('authorized unbind',call(dict(heartbeat,action='unbind'),sid=sid,secret=secret)[0],200)
    after=json.loads(cli('card'))
    import datetime
    dt=lambda s:datetime.datetime.strptime(s,'%Y-%m-%d %H:%M:%S')
    expect('exactly 12h deducted',int((dt(before['expire_time'])-dt(after['expire_time'])).total_seconds()),43200)
    expect('unbound device cleared',after['device_hash'],None)
    expect('old session revoked after unbind',call(dict(heartbeat,action='unbind'),sid=sid,secret=secret)[0],401)
    status,new,_=call(basic);expect('reactivation permitted',status,200);sid=new['data']['session_id'];secret=new['data']['session_key']
    expect('unbind cooldown',call(dict(heartbeat,action='unbind'),sid=sid,secret=secret)[0],409)
    expect('cooldown no extra deduction',json.loads(cli('card'))['expire_time'],after['expire_time'])
    rotated=json.loads(cli('rotate'))
    expect('rotation increments version',rotated['key_version'],2)
    expect('public key-version discovery',call({'action':'config','app_key':A})[1]['data']['key_version'],2)
    expect('old session invalid after rotation',call(heartbeat,sid=sid,secret=secret)[0],401)
    expect('old management version rejected',call(gen,credential='fixture',secret=MANAGEMENT)[0],401)
    status,new,_=call(basic,version=2);expect('new version activation',status,200)
    sid=new['data']['session_id'];secret=new['data']['session_key']
    expect('rotated session works',call(heartbeat,version=2,sid=sid,secret=secret)[0],200)
    jobs=[subprocess.Popen([PHP,str(ROOT/'tests/fixture.php'),'race',d],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE) for d in ['race-device-1','race-device-2']]
    race=[json.loads(p.communicate(timeout=20)[0]) for p in jobs];expect('two-device activation serialized',sum(x['success'] for x in race),1)
    expect('failed restore preserves original data',cli('restore-test').strip(),'rollback-safe restore and schema rejection passed')
    servers.append(launch(no_crypto=True));n=int(cli('count'))
    expect('missing crypto fails closed',call(gen,version=2,credential='fixture',secret=MANAGEMENT,port=servers[-1][1])[0],503)
    expect('crypto failure rolls back generation',int(cli('count')),n)
    expect('web installer removed',call(method='GET',path='/install.php')[0],410)
    servers.append(launch(overrides={'GUYI_ALLOW_LOCAL_HTTP':'0'}))
    expect('production transport rejects HTTP',call(basic,port=servers[-1][1])[0],426)
    expect('legacy remember cookie ignored',call(method='GET',path='/admin/cards.php',headers={'Cookie':'admin_trust=legacy-fixture'})[0],302)
    expect('admin requires login',call(method='GET',path='/admin/cards.php')[0],302)
    status,html,h=call(method='GET',path='/login/login.php');expect('login page',status,200)
    session_cookie=next(c for c in h.get_all('Set-Cookie') if c.startswith('PHPSESSID='))
    expect('session Secure flag','secure' in session_cookie.lower(),True)
    expect('session HttpOnly flag','httponly' in session_cookie.lower(),True)
    expect('session SameSite flag','samesite=strict' in session_cookie.lower(),True)
    cookie=session_cookie.split(';')[0];old_cookie=cookie;csrf=re.search(r'name="csrf_token" value="([^"]+)"',html).group(1)
    form=urllib.parse.urlencode({'username':'fixture-admin','password':'fixture-password-only-123','csrf_token':csrf}).encode()
    status,_,h=call(path='/login/login.php',raw=form,headers={'Content-Type':'application/x-www-form-urlencoded','Cookie':cookie});expect('admin login',status,302)
    cookie=next(c.split(';')[0] for c in h.get_all('Set-Cookie') if c.startswith('PHPSESSID='))
    expect('login regenerates session',cookie!=old_cookie,True)
    marker='\"><span id="audit-probe">marker</span><input value="'
    status,html,_=call(method='GET',path='/admin/cards.php?tab=list&limit=0&filter='+urllib.parse.quote(marker),headers={'Cookie':cookie});expect('safe pagination and admin page',status,200);expect('reflected markup neutralized','id="audit-probe"' in html,False)
    csrf=re.search(r'name="csrf_token" value="([^"]+)"',html).group(1)
    form=urllib.parse.urlencode({'update_settings':'1','csrf_token':csrf}).encode()
    expect('settings saved',call(path='/admin/cards.php',raw=form,headers={'Content-Type':'application/x-www-form-urlencoded','Cookie':cookie})[0],200)
    settings=json.loads(cli('settings'));expect('title preserved',settings['site_title'],'KEEP-TITLE');expect('encryption cannot be disabled',settings['api_encrypt'],'1')
    form=urllib.parse.urlencode({'logout':'1','csrf_token':'incorrect'}).encode()
    expect('logout CSRF rejection',call(path='/admin/cards.php',raw=form,headers={'Content-Type':'application/x-www-form-urlencoded','Cookie':cookie})[0],403)
    cli('change-password');expect('password change revokes existing session',call(method='GET',path='/admin/cards.php',headers={'Cookie':cookie})[0],302)
    status,html,h=call(method='GET',path='/login/login.php');cookie=next(c.split(';')[0] for c in h.get_all('Set-Cookie') if c.startswith('PHPSESSID='));csrf=re.search(r'name="csrf_token" value="([^"]+)"',html).group(1)
    form=urllib.parse.urlencode({'username':'rate-target','password':'incorrect','csrf_token':csrf}).encode()
    attempts=[call(path='/login/login.php',raw=form,headers={'Content-Type':'application/x-www-form-urlencoded','Cookie':cookie})[0] for _ in range(11)]
    expect('account login rate limit',attempts,[401]*10+[429])
    statuses=[call({'action':'config','app_key':A},version=2)[0] for _ in range(61)]
    expect('API rate limit applied',429 in statuses,True)
    print('SUMMARY:',count,'checks passed',flush=True)
finally:
    for p,port,log in servers:
        p.terminate();p.wait(timeout=10);log.close()
