"""Real downloads of isolated saved data, masks, draft protection and errors."""
import csv,io,json,os
from pathlib import Path
from http_test import HTTPTests
from playwright.sync_api import sync_playwright,expect
HTTPTests.setUpClass()
try:
 api=HTTPTests();_,h,_=api.request('api/index.php');token=h['X-CSRF-Token']
 _,_,body=api.request('api/index.php','POST',{'title':'=秘密fixture','description':'日本語\n"説明",二行','dates':['2026-11-02']},token=token);row=json.loads(body);route='api/index.php?route=/instances/'+row['id']
 _,_,body=api.request(route+'/groups','POST',{'expected_version':1,'expected_schema_revision':1,'name':'=group'},token=token);group=json.loads(body)['id']
 for i in range(2):
  assert api.request(route+'/participants','POST',{'expected_version':1,'expected_schema_revision':2,'display_name':'=同名','submission_key':str(i)*32,'group_ids':[group] if i==0 else [],'answers':[{'slot_id':row['days'][0]['slots'][6]['id'],'state':'yes'}] if i==0 else []},token=token)[0]==201
 with sync_playwright() as p:
  browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BINARY','/usr/bin/chromium'),headless=True,args=['--no-sandbox'])
  page=browser.new_page(extra_http_headers={'Authorization':HTTPTests.auth},accept_downloads=True,viewport={'width':1280,'height':1600});errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.on('dialog',lambda d:d.accept())
  url=HTTPTests.origin+'/gyugyu/event.php?id='+row['id'];page.goto(url);expect(page.locator('#export-download')).to_be_visible()
  def download(format,scope='all'):
   page.locator('#export-format').select_option(format)
   if format!='json':page.locator('#export-scope').select_option(scope)
   with page.expect_download() as result:page.locator('#export-download').click()
   item=result.value;data=Path(item.path()).read_bytes();assert item.failure() is None
   assert item.suggested_filename.startswith('gyugyu-'+row['id']+'-');assert '秘密' not in item.suggested_filename
   expect(page.locator('#export-status')).to_contain_text('ダウンロードを開始')
   return data
  held=[]
  page.route('**/api/index.php?route=**',lambda r:held.append(r) if '/export' in r.request.url or '%2Fexport' in r.request.url else r.continue_())
  with page.expect_download() as pending:
   page.locator('#export-download').click()
   expect(page.locator('#candidate-edit')).to_be_disabled();expect(page.locator('#participant-add')).to_be_disabled();expect(page.locator('#group-mode')).to_be_disabled();expect(page.locator('#summary-mode')).to_be_disabled();expect(page.locator('#metadata-editor')).to_be_hidden()
   assert len(held)==1;held[0].continue_()
  assert pending.value.failure() is None;page.unroute('**/api/index.php?route=**')
  expect(page.locator('#export-download')).to_be_enabled()
  original=json.loads(download('json'));assert original['instance']['description']==row['description'];assert original['participants'][0]['display_name']=='=同名';assert len(original['participants'])==2
  page.locator('#group-mode').select_option('include');page.locator(f'#group-filter input[value="{group}"]').check()
  wide=list(csv.DictReader(io.StringIO(download('wide','mask').decode('utf-8-sig'))));assert len(wide)==1;assert wide[0]['name']=="'=同名";assert list(wide[0].values())[-18]=='○'
  long=list(csv.DictReader(io.StringIO(download('long','all').decode('utf-8-sig'))));assert len(long)==36;assert len(set(r['participant_id'] for r in long))==2
  summary=list(csv.DictReader(io.StringIO(download('summary','mask').decode('utf-8-sig'))));assert summary[0]['N']=='1';assert summary[0]['Y']=='1'
  assert len(json.loads(download('json'))['participants'])==2
  page.locator('#participant-add').click();page.locator('#participant-name').fill('未保存');expect(page.locator('#export-download')).to_be_disabled();page.locator('#answer-cancel').click()
  page.locator('#candidate-edit').click();expect(page.locator('#export-download')).to_be_disabled();page.locator('#candidate-cancel').click()
  page.locator('#metadata-editor summary').click();page.locator('#metadata-editor [name=title]').fill('未保存title');expect(page.locator('#export-download')).to_be_disabled();page.locator('#metadata-editor a').click();expect(page.locator('#export-download')).to_be_visible()
  page.route('**/api/index.php?route=**',lambda r:r.abort() if '/export' in r.request.url or '%2Fexport' in r.request.url else r.continue_())
  page.locator('#export-download').click();expect(page.locator('#export-status')).to_contain_text('通信に失敗');page.unroute('**/api/index.php?route=**')
  # A separate client's persisted update is exported, with its revision, without changing local forms.
  assert api.request(route,'PATCH',{'expected_version':1,'title':'更新済み'},token=token)[0]==200
  latest=json.loads(download('json'));assert latest['instance']['title']=='更新済み';assert latest['instance']['version']==2
  expect(page.locator('h1')).to_have_text('=秘密fixture')
  page.set_viewport_size({'width':390,'height':844});assert page.locator('.candidate-cell').first.bounding_box()['width']==16
  assert json.loads(download('json'))['instance']['title']=='更新済み'
  assert not errors,errors;browser.close()
 print('PASS: Chromium CSV/JSON downloads, masks, raw JSON, draft protection, network failure, latest saved version and mobile')
finally:HTTPTests.tearDownClass()
