"""Explicit reception transitions, coherent confirmation, draft protection and mobile."""
import json,os
from http_test import HTTPTests
from playwright.sync_api import sync_playwright,expect
HTTPTests.setUpClass()
try:
 api=HTTPTests();_,h,_=api.request('api/index.php');token=h['X-CSRF-Token']
 _,_,body=api.request('api/index.php','POST',{'title':'受付UI','dates':['2026-11-03']},token=token);row=json.loads(body);route='api/index.php?route=/instances/'+row['id']
 def saved():return json.loads(api.request(route)[2])
 def call(path,method,data):
  code,_,body=api.request(route+path,method,data,token=token);assert code in [200,201],(code,body);return json.loads(body)
 def rev():
  r=saved();return {'expected_version':r['version'],'expected_schema_revision':r['schema_revision'],'expected_answers_revision':r['answers_revision']}
 person=call('/participants','POST',dict(rev(),display_name='参加者',submission_key='a'*32,answers=[]))
 with sync_playwright() as p:
  browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BINARY','/usr/bin/chromium'),headless=True,args=['--no-sandbox'])
  context=browser.new_context(extra_http_headers={'Authorization':HTTPTests.auth},viewport={'width':1280,'height':1200});page=context.new_page();errors=[]
  page.on('pageerror',lambda e:errors.append(str(e)));page.on('dialog',lambda d:d.accept())
  detail=HTTPTests.origin+'/gyugyu/event.php?id='+row['id'];manage=HTTPTests.origin+'/gyugyu/reception.php?id='+row['id'];page.goto(detail)
  page.locator('#participant-add').click();page.locator('#participant-name').fill('下書き');page.locator('#reception-manage').dispatch_event('click');assert page.url==detail;page.locator('#answer-cancel').click()
  page.locator('#candidate-edit').click();page.locator('#reception-manage').dispatch_event('click');assert page.url==detail;page.locator('#candidate-cancel').click()
  page.locator('#metadata-editor summary').click();page.locator('#reception-manage').dispatch_event('click');assert page.url==detail;page.locator('#metadata-editor a',has_text='取消').click()
  held=[];page.route('**/api/index.php?*export*',lambda r:held.append(r));page.locator('#export-download').click();expect(page.locator('#export-status')).to_contain_text('生成中');page.locator('#reception-manage').dispatch_event('click');assert page.url==detail
  for _ in range(100):
   if held:break
   page.wait_for_timeout(50)
  assert held,'export request did not reach the route handler'
  with page.expect_download():held[0].continue_()
  page.unroute('**/api/index.php?*export*');expect(page.locator('#export-status')).to_contain_text('ダウンロードを開始')
  page.locator('#reception-manage').click();form=page.locator('#reception-manager form');field=form.locator('input');save=form.locator('[type=submit]');field.fill('受付UI');form.locator('[type=reset]').click();expect(field).to_have_value('');assert saved()['status']=='open'
  field.fill('受付UI');call('/participants/'+person['id']+'/submission','PUT',dict(rev(),expected_participant_version=1,display_name='参加者',answers=[]))
  save.click();expect(page.locator('#reception-status')).to_contain_text('競合');expect(field).to_have_value('受付UI');expect(save).to_have_text('受付を終了する')
  page.locator('#reception-reload').click();field.fill('受付UI');call('/groups','POST',dict(rev(),name='群'));save.click();expect(page.locator('#reception-status')).to_contain_text('競合')
  page.locator('#reception-reload').click();field.fill('受付UI')
  editor=context.new_page();editor.on('dialog',lambda d:d.accept());editor.on('pageerror',lambda e:errors.append(str(e)));editor.goto(detail);editor.locator('.participant-edit').click();editor.locator('#participant-name').fill('古い下書き')
  pattern='**/api/index.php?route=**/reception';page.route(pattern,lambda r:r.abort());save.click();expect(page.locator('#reception-status')).to_contain_text('通信に失敗');expect(field).to_have_value('受付UI');assert saved()['status']=='open';page.unroute(pattern)
  def loss(r):r.fetch();r.abort()
  page.route(pattern,loss);save.click();expect(page.locator('#reception-status')).to_contain_text('通信に失敗');assert saved()['status']=='locked';page.unroute(pattern)
  for _ in range(2):save.click();expect(page.locator('#reception-status')).to_contain_text('競合');expect(save).to_have_text('受付を終了する')
  editor.locator('#answer-save').click();expect(editor.locator('#answer-status')).to_contain_text('競合');expect(editor.locator('#participant-name')).to_have_value('古い下書き')
  page.goto(detail);expect(page.locator('#metadata-editor input[name=title]')).to_be_disabled();expect(page.locator('#participant-add')).to_be_hidden();expect(page.locator('.participant-edit')).to_be_disabled();expect(page.locator('.summary-row')).to_have_count(3)
  page.locator('#group-mode').select_option('include');expect(page.locator('#group-status')).to_contain_text('対象0人')
  for fmt in ['json','wide']:
   page.locator('#export-format').select_option(fmt)
   with page.expect_download() as download:page.locator('#export-download').click()
   content=open(download.value.path(),encoding='utf-8-sig').read();assert 'locked' in content
  page.locator('#reception-manage').click();expect(save).to_have_text('受付を再開する');field.fill('受付UI');save.focus();page.keyboard.press('Enter');expect(page.locator('#reception-status')).to_contain_text('保存しました');assert saved()['status']=='open'
  editor.locator('#answer-save').click();expect(editor.locator('#answer-status')).to_contain_text('競合');editor.locator('#answer-cancel').click();editor.reload();editor.locator('.participant-edit').click();editor.locator('#participant-name').fill('再開後');editor.locator('#answer-save').click();expect(editor.locator('#answer-status')).to_contain_text('保存完了')
  # Zero-day/zero-participant instance, with actual mobile touch transitions.
  _,_,body=api.request('api/index.php','POST',{'title':'空','dates':['2026-11-04']},token=token);empty=json.loads(body);eroute='api/index.php?route=/instances/'+empty['id'];req={'action':'archive','expected_version':1,'expected_schema_revision':1,'day_id':empty['days'][0]['id']}
  code,_,body=api.request(eroute+'/days/preview','POST',req,token=token);assert code==200;plan=json.loads(body)
  code,_,_=api.request(eroute+'/days','DELETE',dict(req,confirmed_operation='archive',confirmation='2026-11-04',expected_answers_revision=plan['answers_revision'],expected_answer_count=0,expected_visible_answers=0),token=token);assert code==200
  mobile=browser.new_context(extra_http_headers={'Authorization':HTTPTests.auth},viewport={'width':390,'height':844},is_mobile=True,has_touch=True);mp=mobile.new_page();mp.on('pageerror',lambda e:errors.append(str(e)));mp.on('dialog',lambda d:d.accept());mp.goto(HTTPTests.origin+'/gyugyu/reception.php?id='+empty['id']);expect(mp.locator('#reception-manager')).to_contain_text('有効候補日0日／有効参加者0人')
  for label in ['受付を終了する','受付を再開する']:
   expect(mp.locator('form [type=submit]')).to_have_text(label);mp.locator('form input').fill('空');mp.locator('form [type=submit]').tap();expect(mp.locator('form [type=submit]')).to_have_text('受付を再開する' if label=='受付を終了する' else '受付を終了する');expect(mp.locator('#reception-status')).to_contain_text('保存しました')
  assert mp.evaluate('document.documentElement.scrollWidth<=innerWidth');assert not errors,errors;browser.close()
 print('PASS: Chromium reception cancel, draft/export guards, answer/schema conflicts, lost response/repeated commands, locked downloads, stale ABA edit, reopen and zero-day mobile')
finally:HTTPTests.tearDownClass()
