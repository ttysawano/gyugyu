"""Real HTTP requests against the local Basic-auth router and production entry points."""
import base64
from datetime import datetime, timedelta
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

    def setUp(self):
        self.client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

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
        jst=(datetime.fromisoformat(row['updated_at'])+timedelta(hours=9)).strftime('%Y-%m-%d %H:%M:%S')+' JST'
        self.assertIn(jst,detail); self.assertIn(jst,portal)
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
        self.assertEqual(self.request('api/index.php','POST',dict(payload,dates=['2026-11-02\0bad']),token=token)[0],422)
        # Test form preview and validation, using the same CSRF token.
        form={'csrf':token,'action':'preview','title':'プレビュー','description':'','dates':'2026-11-02\n2026-11-02','start':'22:00','end':'06:00','end_next_day':'1'}
        req=urllib.request.Request(self.origin+'/gyugyu/create.php',data=urllib.parse.urlencode(form).encode(),headers={'Authorization':self.auth,'Origin':self.origin})
        page=self.client.open(req).read().decode()
        self.assertIn('重複した日付を1件',page); self.assertIn('翌日06:00',page); self.assertIn('16候補スロット',page)
        for path in ['var/gyugyu.sqlite','src/Schedules.php','database/migrations/001_initial.sql','../AGENTS.md']:
            self.assertEqual(self.request(path)[0],404)

    def test_candidate_masks(self):
        _,headers,_=self.request('api/index.php'); token=headers['X-CSRF-Token']
        status,_,body=self.request('api/index.php','POST',{'title':'候補API','dates':['2026-11-02','2026-11-03']},token=token)
        self.assertEqual(status,201); row=json.loads(body)
        route='api/index.php?route=/instances/'+row['id']+'/candidate-slots/bulk'
        values=[bool(slot['is_candidate']) for slot in row['days'][0]['slots']]; values[6]=False
        payload={'expected_version':1,'expected_schema_revision':1,'expected_answers_revision':1,'confirm_exclusion':True,'masks':[{'day_id':row['days'][0]['id'],'candidates':values}]}
        self.assertEqual(self.request(route,'PATCH',payload,auth=False,token=token)[0],401)
        self.assertEqual(self.request(route,'PATCH',payload)[0],403)
        self.assertEqual(self.request(route,'PATCH',payload,token=token,origin='https://evil.example')[0],403)
        self.assertEqual(self.request(route,'PATCH',dict(payload,confirm_exclusion=False),token=token)[0],422)
        status,_,body=self.request(route,'PATCH',payload,token=token)
        self.assertEqual(status,200); saved=json.loads(body)
        self.assertEqual(saved['schema_revision'],2); self.assertEqual(saved['version'],1)
        self.assertEqual(saved['days'][0]['slots'][6]['id'],row['days'][0]['slots'][6]['id'])
        self.assertEqual(self.request(route,'PATCH',payload,token=token)[0],409)
        self.assertEqual(self.request(route,'GET')[0],405)
        self.assertEqual(self.request('assets/candidates.mjs',auth=False)[0],401)
        status,headers,_=self.request('assets/candidates.mjs')
        self.assertEqual(status,200); self.assertIn('javascript',headers['Content-Type'])

    def test_participant_submission(self):
        _,headers,_=self.request('api/index.php'); token=headers['X-CSRF-Token']
        _,_,body=self.request('api/index.php','POST',{'title':'回答API','dates':['2026-11-02']},token=token)
        row=json.loads(body); route='api/index.php?route=/instances/'+row['id']; slot=row['days'][0]['slots'][6]['id']
        data={'expected_version':1,'expected_schema_revision':1,'submission_key':'c'*32,'display_name':'<b>参加者</b>','group_ids':[],'answers':[{'slot_id':slot,'state':'maybe'}]}
        for auth,tok,origin in [(False,token,True),(True,None,True),(True,token,'https://evil.example')]:
            self.assertEqual(self.request(route+'/participants','POST',data,auth=auth,token=tok,origin=origin)[0],401 if not auth else 403)
        status,_,body=self.request(route+'/participants','POST',data,token=token)
        self.assertEqual(status,201); participant=json.loads(body); pid=participant['id']
        self.assertNotIn('submission_key',participant)
        self.assertEqual(self.request(route+'/participants','POST',data,token=token)[0],409)
        page=self.request('event.php?id='+row['id'])[2]
        self.assertIn('&lt;b&gt;参加者&lt;/b&gt;',page); self.assertNotIn('<b>参加者</b>',page)
        # Independent browser session, same shared Basic account: no person-specific secret.
        self.client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        _,headers,_=self.request('api/index.php'); token=headers['X-CSRF-Token']
        update={'expected_version':1,'expected_schema_revision':1,'expected_participant_version':1,'display_name':'別端末で修正','answers':[]}
        self.assertEqual(self.request(route+'/participants/'+pid+'/submission','PUT',dict(update,answers={}),token=token)[0],422)
        status,_,body=self.request(route+'/participants/'+pid+'/submission','PUT',update,token=token)
        self.assertEqual(status,200); saved=json.loads(body)
        self.assertEqual(saved['answers'],[]); self.assertIsNotNone(saved['submitted_at'])
        self.assertEqual(self.request(route+'/participants/'+pid+'/submission','PUT',update,token=token)[0],409)
        self.assertEqual(self.request('assets/answers.mjs',auth=False)[0],401)
        self.assertEqual(self.request('assets/answers.mjs')[0],200)
        mask={'expected_version':1,'expected_schema_revision':1,'masks':[{'day_id':row['days'][0]['id'],'candidates':[False]*48}]}
        status,_,body=self.request(route+'/candidate-slots/preview','POST',mask,token=token)
        self.assertEqual(status,200); impact=json.loads(body); self.assertEqual(impact['affected_answers'],0)
        update.update(expected_participant_version=2,answers=[{'slot_id':slot,'state':'no'}])
        self.assertEqual(self.request(route+'/participants/'+pid+'/submission','PUT',update,token=token)[0],200)
        mask.update(expected_answers_revision=impact['answers_revision'],confirm_exclusion=True)
        self.assertEqual(self.request(route+'/candidate-slots/bulk','PATCH',mask,token=token)[0],409)

    def test_groups(self):
        _,headers,_=self.request('api/index.php');token=headers['X-CSRF-Token']
        _,_,body=self.request('api/index.php','POST',{'title':'groups','dates':['2026-11-02']},token=token)
        row=json.loads(body);route='api/index.php?route=/instances/'+row['id']
        data={'expected_version':1,'expected_schema_revision':1,'name':'constructor'}
        for path in ['groups.php?id='+row['id'],'assets/groups.mjs',route+'/groups']:
            self.assertEqual(self.request(path,auth=False)[0],401)
        for t,o in [(None,True),(token,False),(token,'https://evil.example')]:
            self.assertEqual(self.request(route+'/groups','POST',data,token=t,origin=o)[0],403)
        code,_,body=self.request(route+'/groups','POST',data,token=token);self.assertEqual(code,201);group=json.loads(body)
        self.assertEqual(self.request(route+'/groups','POST',data,token=token)[0],409)
        payload={'expected_version':1,'expected_schema_revision':2,'expected_participant_version':0,'submission_key':'a'*32,'display_name':'所属','answers':[],'group_ids':[group['id']]}
        code,_,body=self.request(route+'/participants','POST',payload,token=token);self.assertEqual(code,201);person=json.loads(body)
        self.assertEqual(person['group_ids'],[group['id']])
        data={'expected_version':1,'expected_schema_revision':2,'expected_group_version':1,'name':'改名','description':'<script>alert(1)</script>'}
        self.assertEqual(self.request(route+'/groups/'+group['id'],'PATCH',data,token=token)[0],200)
        page=self.request('groups.php?id='+row['id'])[2];self.assertIn('&lt;script&gt;',page)
        archive=dict(data,expected_schema_revision=3,expected_group_version=2,expected_answers_revision=1,confirmation='改名')
        self.assertEqual(self.request(route+'/groups/'+group['id'],'DELETE',archive,token=token)[0],409)
        archive['expected_answers_revision']=2
        self.assertEqual(self.request(route+'/groups/'+group['id'],'DELETE',archive,token=token)[0],200)
        self.assertEqual(json.loads(self.request(route)[2])['participants'][0]['group_ids'],[])
        self.assertEqual(self.request(route+'/groups/'+group['id']+'/restore','POST',dict(archive,expected_schema_revision=4,expected_group_version=3),token=token)[0],200)
        self.assertEqual(json.loads(self.request(route)[2])['participants'][0]['group_ids'],[group['id']])
        for i in range(7):
            data={'expected_version':1,'expected_schema_revision':5+i,'name':'group'+str(i)}
            self.assertEqual(self.request(route+'/groups','POST',data,token=token)[0],201)
        # Independent PHP processes race for ninth slot against the same expected revision.
        script="require "+json.dumps(str(ROOT/'src/bootstrap.php'))+"; try { (new Gyugyu\\Groups(Gyugyu\\Database::connect()))->change($argv[1],null,'create',['expected_version'=>1,'expected_schema_revision'=>12,'name'=>$argv[2]]); echo '201'; } catch (Gyugyu\\Problem $e) {echo $e->getCode();}"
        processes=[subprocess.Popen([PHP,'-r',script,row['id'],'race'+str(i)],env=self.env,stdout=subprocess.PIPE,stderr=subprocess.PIPE) for i in range(2)]
        results=[process.communicate(timeout=10) for process in processes]
        self.assertEqual(sorted(out.decode() for out,err in results),['201','409'])
        self.assertEqual(len(json.loads(self.request(route)[2])['groups']),9)

    def test_archived_group_form_conflict_keeps_draft(self):
        _,headers,_=self.request('api/index.php');token=headers['X-CSRF-Token']
        _,_,body=self.request('api/index.php','POST',{'title':'fallback','dates':['2026-11-02']},token=token)
        row=json.loads(body);route='api/index.php?route=/instances/'+row['id']
        _,_,body=self.request(route+'/groups','POST',{'expected_version':1,'expected_schema_revision':1,'name':'original'},token=token)
        group=json.loads(body)
        draft={'csrf':token,'action':'edit','group_id':group['id'],'expected_version':'1','expected_schema_revision':'2','expected_group_version':'1','expected_answers_revision':'1','name':'<img src=x onerror=alert(1)>','description':'下書き\n</textarea><script>alert(1)</script>'}
        archive={'expected_version':1,'expected_schema_revision':2,'expected_group_version':1,'expected_answers_revision':1,'confirmation':'original'}
        self.assertEqual(self.request(route+'/groups/'+group['id'],'DELETE',archive,token=token)[0],200)
        for attempt in range(2):
            # Ordinary HTML form request, with no JavaScript fetch handler.
            req=urllib.request.Request(self.origin+'/gyugyu/groups.php?id='+row['id'],data=urllib.parse.urlencode(draft).encode(),headers={'Authorization':self.auth,'Origin':self.origin})
            with self.assertRaises(urllib.error.HTTPError) as error:self.client.open(req)
            self.assertEqual(error.exception.code,409);page=error.exception.read().decode()
            self.assertIn('対象グループは現在アーカイブ済みです',page)
            snapshot=re.search(r'<section id="group-failed-draft">(.*?)</section>',page,re.S).group(1)
            self.assertIn('&lt;img src=x onerror=alert(1)&gt;',snapshot)
            self.assertIn('下書き\n&lt;/textarea&gt;&lt;script&gt;alert(1)&lt;/script&gt;',snapshot)
            self.assertNotIn('<script>',snapshot);self.assertNotIn('<form',snapshot)
            for key in ['action','group_id','expected_version','expected_schema_revision','expected_group_version','expected_answers_revision']:
                self.assertIn('<dt>'+key+'</dt><dd>'+draft[key]+'</dd>',snapshot)
            current=json.loads(self.request(route)[2]);self.assertEqual(current['groups'],[]);self.assertEqual(current['schema_revision'],3)
            archived=json.loads(self.request(route+'/groups&archived=1')[2])['groups'][0]
            self.assertEqual(archived['name'],'original');self.assertEqual(archived['description'],'');self.assertEqual(archived['version'],2)
        # A lifecycle change can remove every form, even while the group remains active.
        self.assertEqual(self.request(route+'/groups/'+group['id']+'/restore','POST',dict(archive,expected_schema_revision=3,expected_group_version=2),token=token)[0],200)
        draft.update(expected_schema_revision='4',expected_group_version='3')
        import sqlite3
        with sqlite3.connect(self.env['GYUGYU_DB']) as db:
            db.execute("UPDATE instances SET status='locked',version=version+1 WHERE id=?",(row['id'],))
        req=urllib.request.Request(self.origin+'/gyugyu/groups.php?id='+row['id'],data=urllib.parse.urlencode(draft).encode(),headers={'Authorization':self.auth,'Origin':self.origin})
        with self.assertRaises(urllib.error.HTTPError) as error:self.client.open(req)
        self.assertEqual(error.exception.code,409);page=error.exception.read().decode()
        self.assertIn('受付中の案件のみ変更できます',page);self.assertNotIn('<form',page)
        snapshot=re.search(r'<section id="group-failed-draft">(.*?)</section>',page,re.S).group(1)
        self.assertIn('&lt;img src=x onerror=alert(1)&gt;',snapshot)
        self.assertIn('下書き\n&lt;/textarea&gt;&lt;script&gt;alert(1)&lt;/script&gt;',snapshot)
        for key in ['expected_version','expected_schema_revision','expected_group_version','expected_answers_revision']:
            self.assertIn('<dt>'+key+'</dt><dd>'+draft[key]+'</dd>',snapshot)
        current=json.loads(self.request(route)[2]);self.assertEqual(current['status'],'locked');self.assertEqual(current['version'],2)
        self.assertEqual(current['groups'][0]['name'],'original');self.assertEqual(current['groups'][0]['version'],3)

    def test_summary_snapshot(self):
        _,h,_=self.request('api/index.php');token=h['X-CSRF-Token']
        _,_,body=self.request('api/index.php','POST',{'title':'summary','dates':['2026-11-02','2026-11-03']},token=token);row=json.loads(body);route='api/index.php?route=/instances/'+row['id']
        _,_,body=self.request(route+'/groups','POST',{'expected_version':1,'expected_schema_revision':1,'name':'team'},token=token);group=json.loads(body)['id']
        people=[]
        for i,state in enumerate(['yes','no','unknown']):
            payload={'expected_version':1,'expected_schema_revision':2,'display_name':'person'+str(i),'submission_key':str(i)*32,'group_ids':[group] if i<2 else [],'answers':[] if state=='unknown' else [{'slot_id':row['days'][0]['slots'][6]['id'],'state':state}]}
            code,_,body=self.request(route+'/participants','POST',payload,token=token);self.assertEqual(code,201);people.append(json.loads(body))
        before=json.loads(self.request(route)[2]);endpoint=route+'/summary'
        self.assertEqual(self.request(endpoint,auth=False)[0],401);self.assertEqual(self.request('assets/summary.mjs',auth=False)[0],401)
        code,headers,body=self.request(endpoint);self.assertEqual(code,200);self.assertIn('no-store',headers['Cache-Control']);summary=json.loads(body)
        self.assertEqual(summary['N'],3);self.assertEqual(summary['answers_revision'],4)
        slot=summary['slots'][6];self.assertEqual(slot['counts'],{'yes':1,'maybe':0,'no':1,'unknown':1});self.assertEqual(slot['score'],-3);self.assertTrue(slot['provisional'])
        self.assertEqual(summary['slots'][0]['score'],None);self.assertNotIn(summary['slots'][0]['id'],summary['ranking'])
        empty=json.loads(self.request(endpoint+'&mode=include')[2]);self.assertEqual(empty['N'],0);self.assertEqual(empty['ranking'],[]);self.assertFalse(empty['slots'][6]['all_yes'])
        selected=json.loads(self.request(endpoint+'&mode=include&group_ids[]='+group+'&group_ids[]='+group)[2]);self.assertEqual(selected['N'],2);self.assertFalse(selected['slots'][6]['provisional'])
        self.assertEqual(json.loads(self.request(endpoint+'&mode=exclude')[2])['N'],3)
        self.assertEqual(self.request(endpoint+'&mode=include&group_ids[]=foreign')[0],422)
        self.assertEqual(self.request(endpoint+'&mode[]=all')[0],422)
        self.assertEqual(self.request(endpoint,'POST',{},token=token)[0],405)
        self.assertEqual(self.request(endpoint,'POST',{},token=token,origin='https://evil.example')[0],403)
        self.assertEqual(json.loads(self.request(route)[2]),before)
        # Lifecycle states remain fixture-only: read paths exclude archived/inactive data.
        import sqlite3
        with sqlite3.connect(self.env['GYUGYU_DB']) as db:
            db.execute('UPDATE participants SET is_active=0 WHERE id=?',(people[1]['id'],))
            db.execute("UPDATE instance_days SET archived_at='fixture' WHERE id=?",(row['days'][1]['id'],))
        latest=json.loads(self.request(endpoint)[2]);self.assertEqual(latest['N'],2);self.assertEqual(len(latest['slots']),48);self.assertEqual(latest['slots'][6]['counts']['unknown'],1)
        self.assertEqual(self.request('event.php?id='+row['id'])[0],200)
        self.assertEqual(self.request(route+'/groups/'+group,'DELETE',{'expected_version':1,'expected_schema_revision':2,'expected_group_version':1,'expected_answers_revision':4,'confirmation':'team'},token=token)[0],200)
        self.assertEqual(self.request(endpoint+'&mode=include&group_ids[]='+group)[0],422)
        self.assertEqual(json.loads(self.request(endpoint+'&mode=include')[2])['N'],2)

    def test_exports(self):
        import csv,io,sqlite3
        _,h,_=self.request('api/index.php');token=h['X-CSRF-Token']
        _,_,body=self.request('api/index.php','POST',{'title':'=export','description':'\t@evil,"quoted"\n日本語','dates':['2026-11-02']},token=token);row=json.loads(body);route='api/index.php?route=/instances/'+row['id'];endpoint=route+'/export'
        _,_,body=self.request(route+'/groups','POST',{'expected_version':1,'expected_schema_revision':1,'name':'=group'},token=token);group=json.loads(body)['id']
        for i in range(2):
            payload={'expected_version':1,'expected_schema_revision':2,'display_name':'=name','submission_key':str(i)*32,'group_ids':[group] if i==0 else [],'answers':[{'slot_id':row['days'][0]['slots'][6]['id'],'state':'no'}] if i==0 else []}
            self.assertEqual(self.request(route+'/participants','POST',payload,token=token)[0],201)
        with sqlite3.connect(self.env['GYUGYU_DB']) as db: before='\n'.join(db.iterdump())
        self.assertEqual(self.request(endpoint,auth=False)[0],401);self.assertEqual(self.request('assets/exports.mjs',auth=False)[0],401)
        code,headers,body=self.request(endpoint);self.assertEqual(code,200);self.assertIn('application/json',headers['Content-Type']);self.assertIn('no-store',headers['Cache-Control'])
        self.assertRegex(headers['Content-Disposition'],r'^attachment; filename="gyugyu-[a-f0-9]{32}-[0-9TZ]+-all-json\.json"$');self.assertNotIn('export',headers['Content-Disposition'])
        full=json.loads(body);self.assertEqual(full['instance']['description'],row['description']);self.assertEqual(full['instance']['title'],'=export');self.assertIsInstance(full['instance']['version'],int);self.assertIs(full['days'][0]['slots'][0]['is_candidate'],False);self.assertNotIn('submission_key',body)
        for format in ['wide','long','summary']:
            code,headers,body=self.request(endpoint+'&format='+format+'&scope=mask&mode=include&group_ids[]='+group);self.assertEqual(code,200);self.assertIn('text/csv',headers['Content-Type']);self.assertTrue(body.startswith('\ufeff'))
            rows=list(csv.DictReader(io.StringIO(body.lstrip('\ufeff'))));self.assertEqual(rows[0]['title'],"'=export");self.assertEqual(rows[0]['description'],"'"+row['description'])
            if format=='wide':self.assertEqual(len(rows),1);self.assertEqual(rows[0]['name'],"'=name");self.assertEqual(json.loads(rows[0]['groups'])[0]['name'],'=group')
            if format=='long':self.assertEqual(len(rows),18);self.assertEqual(rows[0]['state'],'no');self.assertEqual(rows[0]['slot_start_utc'],'2026-11-02T00:00:00Z');self.assertEqual(rows[0]['slot_start_jst'],'2026-11-02T09:00:00+09:00')
            if format=='summary':self.assertEqual(rows[0]['N'],'1');self.assertEqual(rows[0]['X'],'1');self.assertEqual(rows[0]['score'],'-2')
        for query in ['&format=json&scope=mask','&format=wide&group_ids[]=foreign','&format=wide&slot_ids[]=foreign','&format[]=json','&filename=evil%0D%0AX-Evil:yes']:
            code,headers,_=self.request(endpoint+query);self.assertEqual(code,422);self.assertNotIn('Content-Disposition',headers);self.assertNotIn('X-Evil',headers)
        self.assertEqual(self.request(endpoint,'POST',{},token=token)[0],405);self.assertEqual(self.request(endpoint,'POST',{},token=token,origin='https://evil.example')[0],403)
        with sqlite3.connect(self.env['GYUGYU_DB']) as db:self.assertEqual('\n'.join(db.iterdump()),before)
        # Another connection atomically changes two tables while CLI exports repeatedly read them.
        with sqlite3.connect(self.env['GYUGYU_DB']) as db:
            db.execute("UPDATE instances SET title='v0' WHERE id=?",(row['id'],));db.execute("UPDATE participants SET display_name='v0' WHERE instance_id=?",(row['id'],))
        script="require "+json.dumps(str(ROOT/'src/bootstrap.php'))+"; $e=new Gyugyu\\Export(Gyugyu\\Database::connect()); echo \"ready\\n\";flush();$seen=[];for($i=0;$i<80;$i++){ $x=json_decode($e->generate($argv[1],[])['content'],true);$seen[$x['instance']['title']]=true;foreach($x['participants'] as $p)if($p['display_name']!==$x['instance']['title'])exit(2);usleep(2000); } echo 'snapshot-ok:'.count($seen);"
        proc=subprocess.Popen([PHP,'-r',script,row['id']],env=self.env,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        self.assertEqual(proc.stdout.readline(),b'ready\n')
        with sqlite3.connect(self.env['GYUGYU_DB']) as db:
            for i in range(1,60):
                with db:db.execute('UPDATE instances SET title=? WHERE id=?',('v'+str(i),row['id']));db.execute('UPDATE participants SET display_name=? WHERE instance_id=?',('v'+str(i),row['id']))
                time.sleep(.003)
        out,err=proc.communicate(timeout=15);self.assertEqual(proc.returncode,0,err.decode());self.assertTrue(out.startswith(b'snapshot-ok:'));self.assertGreater(int(out.split(b':')[1]),1)
        self.assertEqual(self.request(route,'DELETE',{'expected_version':1,'confirmation':'v59'},token=token)[0],200)
        self.assertEqual(self.request(endpoint)[0],404)

    def test_date_management(self):
        _,h,_=self.request('api/index.php');token=h['X-CSRF-Token']
        _,_,body=self.request('api/index.php','POST',{'title':'days','dates':['2026-11-03']},token=token);row=json.loads(body);ident=row['id'];route='api/index.php?route=/instances/'+ident;endpoint=route+'/days';day=row['days'][0]
        for path in [endpoint,'days.php?id='+ident,'assets/days.mjs']:self.assertEqual(self.request(path,auth=False)[0],401)
        self.assertEqual(self.request('days.php?id='+ident)[0],200)
        req={'action':'archive','day_id':day['id'],'expected_version':1,'expected_schema_revision':1}
        for t,o in [(None,True),(token,False),(token,'https://evil.example')]:self.assertEqual(self.request(endpoint+'/preview','POST',req,token=t,origin=o)[0],403)
        _,_,body=self.request(endpoint+'/preview','POST',req,token=token);plan=json.loads(body)
        commit=dict(req,confirmed_operation='archive',expected_answers_revision=plan['answers_revision'],expected_answer_count=plan['answer_count'],expected_visible_answers=plan['visible_answers'],confirmation=day['local_date'])
        _,_,body=self.request(route+'/participants','POST',{'expected_version':1,'expected_schema_revision':1,'submission_key':'a'*32,'display_name':'回答','answers':[{'slot_id':day['slots'][6]['id'],'state':'yes'}]},token=token);person=json.loads(body)
        self.assertEqual(self.request(endpoint,'DELETE',commit,token=token)[0],409)
        _,_,body=self.request(endpoint+'/preview','POST',req,token=token);plan=json.loads(body);commit.update(expected_answers_revision=plan['answers_revision'],expected_answer_count=plan['answer_count'],expected_visible_answers=plan['visible_answers'])
        self.assertEqual(plan['answer_count'],1);self.assertEqual(self.request(endpoint,'DELETE',commit,token=token)[0],200)
        self.assertEqual(self.request(endpoint,'DELETE',commit,token=token)[0],409)
        self.assertEqual(json.loads(self.request(route)[2])['days'],[]);self.assertEqual(self.request('event.php?id='+ident)[0],200)
        complete=json.loads(self.request(route+'/export')[2]);self.assertIsNotNone(complete['days'][0]['archived_at']);self.assertEqual(complete['participants'][0]['answers'][0]['slot_id'],day['slots'][6]['id'])
        req={'action':'add','local_date':'2026-11-03','initial_window':{'start':'22:00','end':'06:00','end_next_day':True},'expected_version':1,'expected_schema_revision':2}
        _,_,body=self.request(endpoint+'/preview','POST',req,token=token);plan=json.loads(body);self.assertEqual(plan['operation'],'restore')
        data=dict(req,confirmed_operation='restore',expected_answers_revision=plan['answers_revision'],expected_answer_count=plan['answer_count'],expected_visible_answers=plan['visible_answers'])
        self.assertEqual(self.request(endpoint,'POST',data,token=token)[0],200)
        restored=json.loads(self.request(route)[2]);self.assertEqual(restored['days'][0],day);self.assertEqual(restored['participants'][0]['answers'],person['answers'])
        code,_,body=self.request(endpoint+'/preview','POST',dict(req,expected_schema_revision=3),token=token);self.assertEqual(code,200);self.assertEqual(json.loads(body)['operation'],'existing')
        self.assertEqual(self.request(endpoint+'/preview','POST',dict(req,local_date='2026-02-30',expected_schema_revision=3),token=token)[0],422)
        _,_,other=self.request('api/index.php','POST',{'title':'foreign','dates':['2026-11-03']},token=token)
        self.assertEqual(self.request(endpoint+'/preview','POST',{'action':'archive','day_id':json.loads(other)['days'][0]['id'],'expected_version':1,'expected_schema_revision':3},token=token)[0],404)
        import sqlite3
        with sqlite3.connect(self.env['GYUGYU_DB']) as db:db.execute("UPDATE instances SET status='locked' WHERE id=?",(ident,))
        self.assertEqual(self.request(endpoint+'/preview','POST',dict(req,local_date='2026-11-04',expected_schema_revision=3),token=token)[0],409)
        with sqlite3.connect(self.env['GYUGYU_DB']) as db:db.execute("UPDATE instances SET status='deleted',deleted_at='fixture' WHERE id=?",(ident,))
        self.assertEqual(self.request(endpoint)[0],404);self.assertEqual(self.request('days.php?id='+ident)[0],404)

    def test_date_lifetime_cap_race(self):
        _,h,_=self.request('api/index.php');token=h['X-CSRF-Token']
        dates=[(datetime(2028,1,1)+timedelta(days=i)).strftime('%Y-%m-%d') for i in range(365)]
        status,_,body=self.request('api/index.php','POST',{'title':'date cap race','dates':dates},token=token)
        self.assertEqual(status,201);row=json.loads(body);route='api/index.php?route=/instances/'+row['id']
        requests=[]
        for date in ['2030-01-01','2030-01-02']:
            req={'local_date':date,'expected_version':1,'expected_schema_revision':1}
            status,_,body=self.request(route+'/days/preview','POST',req,token=token);self.assertEqual(status,200);plan=json.loads(body)
            requests.append(dict(req,confirmed_operation='new',expected_answers_revision=plan['answers_revision'],expected_answer_count=0,expected_visible_answers=0))
        # Independent PHP processes contend for the same SQLite writer lock.
        worker='require '+json.dumps(str(ROOT/'src/bootstrap.php'))+r'; try {(new Gyugyu\Days(Gyugyu\Database::connect()))->change($argv[1],"add",json_decode($argv[2],true));echo 200;}catch(Gyugyu\Problem $e){echo $e->getCode();}'
        workers=[subprocess.Popen([PHP,'-r',worker,row['id'],json.dumps(req)],env=self.env,stdout=subprocess.PIPE,stderr=subprocess.PIPE) for req in requests]
        outcomes=[]
        for proc in workers:
            out,err=proc.communicate(timeout=20);self.assertEqual(proc.returncode,0,err);outcomes.append(int(out))
        self.assertEqual(sorted(outcomes),[200,409])
        listing=json.loads(self.request(route+'/days')[2]);self.assertEqual(len(listing['days']),366)
        req={'local_date':'2030-01-03','expected_version':1,'expected_schema_revision':listing['schema_revision']}
        self.assertEqual(self.request(route+'/days/preview','POST',req,token=token)[0],422)
        mixed=dict(req,dates=[dates[0],'2030-01-03'])
        self.assertEqual(self.request(route+'/days','POST',mixed,token=token)[0],422)
        self.assertEqual(json.loads(self.request(route+'/days')[2]),listing)

    def test_backup_restore(self):
        fixture = "require " + json.dumps(str(ROOT/'src/bootstrap.php')) + "; $s=new Gyugyu\\Schedules(Gyugyu\\Database::connect()); $r=$s->create(['title'=>'backup fixture','dates'=>['2026-11-05']]);  $p=new Gyugyu\\Participants(Gyugyu\\Database::connect()); $p->submit($r['id'],null,['display_name'=>'backup participant','expected_version'=>1,'expected_schema_revision'=>1,'submission_key'=>str_repeat('a',32),'answers'=>[['slot_id'=>$r['days'][0]['slots'][6]['id'],'state'=>'yes']]]); echo $r['id'];"
        ident=subprocess.check_output([PHP,'-r',fixture],env=self.env).decode()
        membership="require "+json.dumps(str(ROOT/'src/bootstrap.php'))+"; $db=Gyugyu\\Database::connect(); $s=new Gyugyu\\Schedules($db); $r=$s->get('"+ident+"'); $g=(new Gyugyu\\Groups($db))->change($r['id'],null,'create',['name'=>'backup group','expected_version'=>1,'expected_schema_revision'=>1]); $p=$r['participants'][0]; (new Gyugyu\\Participants($db))->submit($r['id'],$p['id'],['display_name'=>$p['display_name'],'expected_version'=>1,'expected_schema_revision'=>2,'expected_participant_version'=>1,'group_ids'=>[$g['id']],'answers'=>$p['answers']]);"
        subprocess.run([PHP,'-r',membership],env=self.env,check=True,capture_output=True)
        target=self.tmp.name+'/backup.sqlite'
        subprocess.run([PHP,str(ROOT/'bin/backup.php'),target],env=self.env,check=True,capture_output=True)
        checkcode = "require " + json.dumps(str(ROOT/'src/bootstrap.php')) + "; $d=Gyugyu\\Database::connect(); if($d->query('PRAGMA integrity_check')->fetchColumn()!=='ok' || $d->query('PRAGMA foreign_key_check')->fetchAll()!==[]) exit(1); Gyugyu\\Database::migrate($d);"
        subprocess.run([PHP,'-r',checkcode],env=dict(self.env,GYUGYU_DB=target),check=True,capture_output=True)
        restored="require " + json.dumps(str(ROOT/'src/bootstrap.php')) + "; $s=new Gyugyu\\Schedules(Gyugyu\\Database::connect()); $r=$s->get('"+ident+"'); if($r['title']!=='backup fixture' || count($r['days'][0]['slots'])!==48 || count($r['participants'])!==1 || $r['participants'][0]['answers'][0]['state']!=='yes' || count($r['groups'])!==1 || $r['participants'][0]['group_ids']!==[$r['groups'][0]['id']]) exit(1);"
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
