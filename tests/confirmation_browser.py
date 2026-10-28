"""Deliberate slot/mask confirmation, immutable decision, failures, cancel and mobile."""
import json,os
from pathlib import Path
from http_test import HTTPTests
from playwright.sync_api import sync_playwright,expect
HTTPTests.setUpClass()
try:
 api=HTTPTests();_,h,_=api.request('api/index.php');token=h['X-CSRF-Token']
 _,_,body=api.request('api/index.php','POST',{'title':'確定UI','dates':['2026-11-03']},token=token);row=json.loads(body);route='api/index.php?route=/instances/'+row['id'];slot=row['days'][0]['slots'][6]['id']
 def saved():return json.loads(api.request(route)[2])
 def call(path,method,data):
  code,_,body=api.request(route+path,method,data,token=token);assert code in [200,201],(code,body);return json.loads(body)
 def rev():
  r=saved();return {'expected_version':r['version'],'expected_schema_revision':r['schema_revision'],'expected_answers_revision':r['answers_revision']}
 group=call('/groups','POST',dict(rev(),name='<group>'))
 people=[]
 for key in ['a','b']:people.append(call('/participants','POST',dict(rev(),display_name='同名',submission_key=key*32,group_ids=[group['id']],answers=[])))
 with sync_playwright() as p:
  browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BINARY','/usr/bin/chromium'),headless=True,args=['--no-sandbox']);context=browser.new_context(extra_http_headers={'Authorization':HTTPTests.auth},viewport={'width':1280,'height':1200});page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.on('dialog',lambda d:d.accept())
  detail=HTTPTests.origin+'/gyugyu/event.php?id='+row['id'];page.goto(detail)
  page.locator('#participant-add').click();page.locator('#participant-name').fill('下書き');page.locator('#confirmation-manage').dispatch_event('click');assert page.url==detail;page.locator('#answer-cancel').click()
  page.locator('#candidate-edit').click();page.locator('#confirmation-manage').dispatch_event('click');assert page.url==detail;page.locator('#candidate-cancel').click()
  page.locator('#metadata-editor summary').click();page.locator('#confirmation-manage').dispatch_event('click');assert page.url==detail;page.locator('#metadata-editor a').click()
  held=[];page.route('**/api/index.php?*export*',lambda r:held.append(r));page.locator('#export-download').click();expect(page.locator('#export-status')).to_contain_text('生成中');page.locator('#confirmation-manage').dispatch_event('click');assert page.url==detail
  for _ in range(100):
   if held:break
   page.wait_for_timeout(50)
  assert held
  with page.expect_download():held[0].continue_()
  page.unroute('**/api/index.php?*export*');expect(page.locator('#export-status')).to_contain_text('ダウンロードを開始')
  page.locator('#group-mode').select_option('include');page.locator('#group-filter input').check();page.locator('#confirmation-manage').click()
  expect(page.locator('[name=mode]')).to_have_value('include');expect(page.locator('[name=group_ids]')).to_be_checked();expect(page.locator('[name=slot_id]')).to_have_value('');expect(page.locator('#decision-save')).to_be_disabled()
  def preview():
   page.locator('[name=slot_id]').select_option(slot);page.locator('#decision-preview').click();expect(page.locator('#decision-preview-result')).to_contain_text('対象2人')
  def approve():page.locator('[name=confirmation]').fill('確定UI');page.locator('[name=confirm_selection]').check()
  page.locator('[name=slot_id]').select_option(slot);page.locator('[type=reset]').click();expect(page.locator('[name=slot_id]')).to_have_value('')
  preview()
  for person in people:expect(page.locator('#decision-preview-result')).to_contain_text('#'+person['id'][:6])
  approve();page.locator('[type=reset]').click();expect(page.locator('[name=slot_id]')).to_have_value('');assert saved()['confirmation'] is None
  preview();approve();call('/participants/'+people[0]['id']+'/submission','PUT',dict(rev(),expected_participant_version=1,display_name='同名',answers=[]));page.locator('#decision-save').click();expect(page.locator('#confirmation-status')).to_contain_text('競合');expect(page.locator('[name=slot_id]')).to_have_value(slot)
  page.locator('#decision-reload').click();preview();approve();call('/groups/'+group['id'],'PATCH',dict(rev(),expected_group_version=1,name='変更群'));page.locator('#decision-save').click();expect(page.locator('#confirmation-status')).to_contain_text('競合')
  page.locator('#decision-reload').click();preview();approve();pattern='**/api/index.php?route=**/confirmation'
  page.route(pattern,lambda r:r.abort());page.locator('#decision-save').click();expect(page.locator('#confirmation-status')).to_contain_text('通信に失敗');assert saved()['status']=='open';expect(page.locator('[name=confirmation]')).to_have_value('確定UI');page.unroute(pattern)
  def loss(r):r.fetch();r.abort()
  page.route(pattern,loss);page.locator('#decision-save').click();expect(page.locator('#confirmation-status')).to_contain_text('通信に失敗');decision=saved()['confirmation'];assert decision['slot_id']==slot;page.unroute(pattern)
  for _ in range(2):page.locator('#decision-save').click();expect(page.locator('#confirmation-status')).to_contain_text('競合')
  page.goto(detail);expect(page.locator('.confirmation-record')).to_contain_text('2026-11-03 09:00:00 JST');expect(page.locator('.confirmation-record')).to_contain_text('変更群');page.locator('#group-mode').select_option('exclude');expect(page.locator('#group-status')).to_contain_text('対象0人');assert saved()['confirmation']==decision
  with page.expect_download() as download:page.locator('#export-download').click()
  exported=json.loads(Path(download.value.path()).read_text());assert exported['instance']['confirmation']==decision
  page.locator('#confirmation-manage').click();expect(page.locator('#decision-save')).to_have_text('確定を取り消す');approve();page.route('**/api/index.php?route=**/confirmation/cancel',loss);page.locator('#decision-save').click();expect(page.locator('#confirmation-status')).to_contain_text('通信に失敗');assert saved()['status']=='open' and saved()['confirmation'] is None;page.unroute('**/api/index.php?route=**/confirmation/cancel');page.locator('#decision-save').click();expect(page.locator('#confirmation-status')).to_contain_text('競合')
  # Mobile confirms an explicitly empty target set; keyboard triggers preview.
  mobile=browser.new_context(extra_http_headers={'Authorization':HTTPTests.auth},viewport={'width':390,'height':844},is_mobile=True,has_touch=True);mp=mobile.new_page();mp.on('pageerror',lambda e:errors.append(str(e)));mp.on('dialog',lambda d:d.accept());mp.goto(HTTPTests.origin+'/gyugyu/confirmation.php?id='+row['id']);mp.locator('[name=slot_id]').select_option(slot);mp.locator('[name=mode]').select_option('include');mp.locator('#decision-preview').focus();mp.keyboard.press('Enter');expect(mp.locator('#decision-preview-result')).to_contain_text('対象0人');mp.locator('[name=confirmation]').fill('確定UI');mp.locator('[name=confirm_selection]').check();mp.locator('#decision-save').tap();expect(mp.locator('#decision-save')).to_have_text('確定を取り消す');assert saved()['confirmation']['mask']['N']==0
  mp.locator('[name=confirmation]').fill('確定UI');mp.locator('[name=confirm_selection]').check();mp.locator('#decision-save').tap();expect(mp.locator('[name=slot_id]')).to_have_value('');assert saved()['status']=='open';assert mp.evaluate('document.documentElement.scrollWidth<=innerWidth')
  req=dict(rev(),action='archive',day_id=row['days'][0]['id']);plan=call('/days/preview','POST',req);call('/days','DELETE',dict(req,confirmed_operation='archive',confirmation='2026-11-03',expected_answer_count=plan['answer_count'],expected_visible_answers=plan['visible_answers']))
  mp.reload();expect(mp.locator('[name=slot_id] option')).to_have_count(1);mp.locator('#decision-preview').tap();expect(mp.locator('#confirmation-status')).to_contain_text('有効候補');expect(mp.locator('#decision-save')).to_be_disabled();assert saved()['confirmation'] is None
  assert not errors,errors;browser.close()
 print('PASS: Chromium explicit slot/mask, same-name IDs, preview invalidation, cancel, draft/export guards, stale answers/schema, lost confirm/cancel, frozen display/export, keyboard and zero-target/zero-day mobile')
finally:HTTPTests.tearDownClass()
