"""Real HTTP requests against the local Basic-auth router and production entry points."""
import base64
import http.cookiejar
import json
import os
import re
import secrets
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')

class HTTPTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory()
        cls.env = dict(os.environ, GYUGYU_DB=cls.tmp.name+'/test.sqlite', GYUGYU_BASE_PATH='/gyugyu')
        subprocess.run([PHP, str(ROOT/'bin/migrate.php')],env=cls.env,check=True,capture_output=True)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0)); port = sock.getsockname()[1]
        cls.origin = f'http://127.0.0.1:{port}'
        cls.env['GYUGYU_ORIGIN'] = cls.origin
        password = secrets.token_urlsafe(24)
        cls.auth = 'Basic '+base64.b64encode(('developer:'+password).encode()).decode()
        cls.env['GYUGYU_DEV_PASSWORD_HASH'] = subprocess.check_output([PHP,'-r','echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);'],input=password.encode()).decode()
        cls.log = open(cls.tmp.name+'/server.log','w+')
        cls.server = subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t','public','bin/dev-router.php'],cwd=ROOT,env=cls.env,stdout=cls.log,stderr=cls.log)
        cls.client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        for _ in range(60):
            if cls.server.poll() is not None: raise RuntimeError('server failed')
            try:
                urllib.request.urlopen(cls.origin+'/gyugyu/')
            except urllib.error.HTTPError: break
            except urllib.error.URLError: time.sleep(.05)
        else: raise RuntimeError('server not ready')

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate(); cls.server.wait(timeout=5)
        cls.log.close(); cls.tmp.cleanup()

    def request(self, path='', method='GET', data=None, auth=True, token=None, origin=True):
        headers = {}
        if auth: headers['Authorization'] = self.auth
        if token is not None: headers['X-CSRF-Token'] = token
        if origin: headers['Origin'] = self.origin if origin is True else origin
        if data is not None:
            headers['Content-Type']='application/json'; data=json.dumps(data).encode()
        req=urllib.request.Request(self.origin+'/gyugyu/'+path,data=data,method=method,headers=headers)
        try: res=self.client.open(req)
        except urllib.error.HTTPError as e: res=e
        return res.code, res.headers, res.read().decode()

    def test_workflow_and_security(self):
        for path in ['', 'create.php','event.php?id=bad','api/index.php','assets/gyugyu.css','assets/gyugyu-cow.svg']:
            self.assertEqual(self.request(path,auth=False)[0],401)
        status,headers,_=self.request('api/index.php'); self.assertEqual(status,200)
        token=headers['X-CSRF-Token']
        self.assertIn('HttpOnly',headers['Set-Cookie']); self.assertIn('SameSite=Strict',headers['Set-Cookie'])
        payload={'title':'HTTPテスト','description':'<script>private-detail</script>\n二行','dates':['2026-11-02','2026-11-04'],'initial_window':{'start':'09:00','end':'18:00'}}
        for badtoken,origin in [(None,True),(token,'https://evil.example'),(token,False)]:
            self.assertEqual(self.request('api/index.php','POST',payload,token=badtoken,origin=origin)[0],403)
        status,_,body=self.request('api/index.php','POST',payload,token=token)
        self.assertEqual(status,201); row=json.loads(body); ident=row['id']; route='api/index.php?route=/instances/'+ident
        self.assertEqual(len(row['days'][0]['slots']),48)
        self.assertEqual(self.request(route)[0],200)
        portal=self.request()[2]
        self.assertNotIn('private-detail',portal); self.assertIn('href="/gyugyu/event.php?id='+ident+'"',portal)
        detail=self.request('event.php?id='+ident)[2]
        self.assertIn('&lt;script&gt;private-detail&lt;/script&gt;\n二行',detail)
        self.assertNotIn('<script>',detail)
        self.assertIn('/gyugyu/assets/gyugyu.css',detail)
        self.assertEqual(self.request(route,'PATCH',{'expected_version':1,'description':''},token=token)[0],200)
        self.assertEqual(self.request(route,'PATCH',{'expected_version':1,'title':'stale'},token=token)[0],409)
        self.assertEqual(self.request(route,'DELETE',{'expected_version':2,'confirmation':'wrong'},token=token)[0],422)
        self.assertEqual(self.request(route,'DELETE',{'expected_version':2,'confirmation':payload['title']},token=token)[0],200)
        self.assertEqual(self.request(route)[0],404)
        self.assertEqual(self.request('event.php?id='+ident)[0],404)
        self.assertIn('復元',self.request('?deleted=1')[2])
        self.assertEqual(self.request(route+'/restore','POST',{'expected_version':3},token=token)[0],200)
        self.assertEqual(self.request(route)[0],200)
        self.assertEqual(self.request('api/index.php','PUT',{},token=token)[0],405)
        self.assertEqual(self.request('api/index.php','POST',dict(payload,title=[]),token=token)[0],422)
        self.assertEqual(self.request('api/index.php','POST',dict(payload,dates=['bad']),token=token)[0],422)
        # Test form preview and validation, using the same CSRF token.
        form={'csrf':token,'action':'preview','title':'プレビュー','description':'','dates':'2026-11-02\n2026-11-02','start':'22:00','end':'06:00','end_next_day':'1'}
        req=urllib.request.Request(self.origin+'/gyugyu/create.php',data=urllib.parse.urlencode(form).encode(),headers={'Authorization':self.auth,'Origin':self.origin})
        page=self.client.open(req).read().decode()
        self.assertIn('重複した日付を1件',page); self.assertIn('翌日06:00',page); self.assertIn('16候補スロット',page)
        for path in ['var/gyugyu.sqlite','src/Schedules.php','database/migrations/001_initial.sql','../AGENTS.md']:
            self.assertEqual(self.request(path)[0],404)

    def test_backup_restore(self):
        fixture = "require " + json.dumps(str(ROOT/'src/bootstrap.php')) + "; $s=new Gyugyu\\Schedules(Gyugyu\\Database::connect()); $r=$s->create(['title'=>'backup fixture','dates'=>['2026-11-05']]); echo $r['id'];"
        ident=subprocess.check_output([PHP,'-r',fixture],env=self.env).decode()
        target=self.tmp.name+'/backup.sqlite'
        subprocess.run([PHP,str(ROOT/'bin/backup.php'),target],env=self.env,check=True,capture_output=True)
        checkcode = "require " + json.dumps(str(ROOT/'src/bootstrap.php')) + "; $d=Gyugyu\\Database::connect(); if($d->query('PRAGMA integrity_check')->fetchColumn()!=='ok' || $d->query('PRAGMA foreign_key_check')->fetchAll()!==[]) exit(1); Gyugyu\\Database::migrate($d);"
        subprocess.run([PHP,'-r',checkcode],env=dict(self.env,GYUGYU_DB=target),check=True,capture_output=True)
        restored="require " + json.dumps(str(ROOT/'src/bootstrap.php')) + "; $s=new Gyugyu\\Schedules(Gyugyu\\Database::connect()); $r=$s->get('"+ident+"'); if($r['title']!=='backup fixture' || count($r['days'][0]['slots'])!==48) exit(1);"
        subprocess.run([PHP,'-r',restored],env=dict(self.env,GYUGYU_DB=target),check=True,capture_output=True)
        # Existing destinations must not be overwritten.
        self.assertNotEqual(subprocess.run([PHP,str(ROOT/'bin/backup.php'),target],env=self.env,capture_output=True).returncode,0)

    def test_production_fails_closed(self):
        # Direct public PHP entry points ignore forwarded identities and Basic headers.
        for file in ['public/index.php','public/api/index.php']:
            code = "$_SERVER=['REQUEST_METHOD'=>'GET','HTTP_X_REMOTE_USER'=>'forged','PHP_AUTH_USER'=>'forged','HTTPS'=>'on']; require " + json.dumps(str(ROOT/file)) + "; if(http_response_code()!==401) exit(1);"
            result=subprocess.run([PHP,'-r',code],env=self.env,capture_output=True)
            self.assertEqual(result.returncode,0,result.stderr.decode())

if __name__ == '__main__': unittest.main(verbosity=2)
