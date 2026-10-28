"""Date preview, archive/restore fidelity and empty-date recovery in Chromium."""
import json,os
from http_test import HTTPTests
from playwright.sync_api import sync_playwright,expect
HTTPTests.setUpClass()
try:
 api=HTTPTests();_,h,_=api.request('api/index.php');token=h['X-CSRF-Token']
 _,_,body=api.request('api/index.php','POST',{'title':'日付UI','dates':['2026-11-03']},token=token);row=json.loads(body);route='api/index.php?route=/instances/'+row['id'];day=row['days'][0]
 _,_,body=api.request(route+'/participants','POST',{'expected_version':1,'expected_schema_revision':1,'submission_key':'a'*32,'display_name':'参加者','answers':[{'slot_id':day['slots'][6]['id'],'state':'yes'}]},token=token);person=json.loads(body)
 def stored():return json.loads(api.request(route)[2])
 with sync_playwright() as p:
  browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BINARY','/usr/bin/chromium'),headless=True,args=['--no-sandbox'])
  context=browser.new_context(extra_http_headers={'Authorization':HTTPTests.auth},viewport={'width':1280,'height':1600});page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.on('dialog',lambda d:d.accept())
  detail=HTTPTests.origin+'/gyugyu/event.php?id='+row['id'];manage=HTTPTests.origin+'/gyugyu/days.php?id='+row['id'];page.goto(detail)
  page.locator('#participant-add').click();page.locator('#participant-name').fill('下書き');page.locator('#date-manage').dispatch_event('click');assert page.url==detail;page.locator('#answer-cancel').click()
  page.locator('#date-manage').click();page.locator('[data-action=archive]').click();expect(page.locator('#day-preview')).to_contain_text('保存済み回答1件');page.locator('#day-cancel').click();assert len(stored()['days'])==1
  page.locator('[data-action=archive]').click();expect(page.locator('#day-preview')).to_contain_text('アーカイブ')
  # Answer update after preview invalidates its impact confirmation, repeatedly.
  assert api.request(route+'/participants/'+person['id']+'/submission','PUT',{'expected_version':1,'expected_schema_revision':1,'expected_participant_version':1,'display_name':'参加者','answers':person['answers']},token=token)[0]==200
  page.locator('#day-confirm').fill('2026-11-03')
  for _ in range(2):page.locator('#day-save').click();expect(page.locator('#day-status')).to_contain_text('競合');expect(page.locator('#day-confirm')).to_have_value('2026-11-03')
  page.locator('#day-reload').click();page.locator('[data-action=archive]').click();expect(page.locator('#day-preview')).to_contain_text('保存済み回答1件');page.locator('#day-confirm').fill('2026-11-03');page.locator('#day-save').click();expect(page.locator('#day-status')).to_contain_text('アーカイブしました')
  page.goto(detail);expect(page.locator('#candidate-grid')).to_contain_text('有効候補日はありません');expect(page.locator('#group-status')).to_contain_text('対象1人');expect(page.locator('#participant-add')).to_be_hidden();expect(page.locator('#export-download')).to_be_enabled();expect(page.locator('#date-manage')).to_be_visible()
  # Empty-day saved snapshot still downloads all history and has no ranked slots.
  expect(page.locator('#candidate-grid .participant-row')).to_have_count(1)
  with page.expect_download() as download:page.locator('#export-download').click()
  exported=json.loads(open(download.value.path()).read());assert len(exported['days'])==1 and exported['days'][0]['archived_at'] is not None;assert len(exported['participants'])==1
  page.locator('#date-manage').click();page.locator('#day-date').fill('2026-11-03');page.locator('#day-start').fill('22:00');page.locator('#day-next').check();page.locator('#day-end').fill('06:00');page.locator('#day-add').click();expect(page.locator('#day-preview')).to_contain_text('履歴を復元（入力時間帯は適用しません）');page.locator('#day-save').click();expect(page.locator('#day-status')).to_contain_text('履歴を復元しました');assert stored()['days'][0]==day
  page.locator('#day-date').fill('2026-11-03');page.locator('#day-add').click();expect(page.locator('#day-preview')).to_contain_text('既存・変更なし');expect(page.locator('#day-save')).to_be_disabled();page.locator('#day-cancel').click()
  # Transport failure leaves date/window draft unchanged and creates nothing.
  page.locator('#day-date').fill('2026-11-02');page.locator('#day-add').click();expect(page.locator('#day-preview')).to_contain_text('新規作成')
  page.route('**/api/index.php?route=**/days',lambda r:r.abort());page.locator('#day-save').click();expect(page.locator('#day-status')).to_contain_text('通信に失敗');expect(page.locator('#day-date')).to_have_value('2026-11-02');assert len(stored()['days'])==1
  page.unroute('**/api/index.php?route=**/days')
  def loss(r):r.fetch();r.abort()
  page.route('**/api/index.php?route=**/days',loss);page.locator('#day-save').click();expect(page.locator('#day-status')).to_contain_text('通信に失敗');assert len(stored()['days'])==2
  page.unroute('**/api/index.php?route=**/days');page.locator('#day-save').click();expect(page.locator('#day-status')).to_contain_text('競合');assert len(stored()['days'])==2
  page.locator('#day-reload').click();expect(page.locator('#day-inputs article')).to_have_count(2)
  page.goto(detail);expect(page.locator('.day-panel')).to_have_count(2);assert stored()['days'][0]['local_date']=='2026-11-02';assert stored()['participants'][0]['answers']==person['answers']
  expect(page.locator('.day-panel').nth(1).locator('.answer-cell').nth(6)).to_have_text('○')
  page.set_viewport_size({'width':390,'height':844});assert page.locator('.candidate-cell').first.bounding_box()['width']==16;page.locator('#date-manage').click();expect(page.locator('#day-date')).to_be_visible()
  assert not errors,errors;browser.close()
 print('PASS: Chromium date archive/restore, zero-day recovery, fresh impact checks, cancel/failure/lost response, repeated conflict and mobile')
finally:HTTPTests.tearDownClass()
