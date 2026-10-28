"""Real Chromium restoration, original versions, lost response and zero-day mobile."""
import json,os
from http_test import HTTPTests
from playwright.sync_api import sync_playwright,expect
HTTPTests.setUpClass()
try:
 api=HTTPTests();_,h,_=api.request('api/index.php');token=h['X-CSRF-Token']
 _,_,body=api.request('api/index.php','POST',{'title':'参加者復帰','dates':['2026-11-03']},token=token);row=json.loads(body);route='api/index.php?route=/instances/'+row['id']
 def saved():return json.loads(api.request(route)[2])
 def request(path,method,data):
  code,_,body=api.request(route+path,method,data,token=token);assert code in [200,201],(code,body);return json.loads(body)
 def rev():
  r=saved();return {'expected_version':r['version'],'expected_schema_revision':r['schema_revision']}
 people=[]
 for key in ['a','b']:
  person=request('/participants','POST',dict(rev(),submission_key=key*32,display_name='constructor',answers=[]));people.append(person)
  request('/participants/'+person['id'],'PATCH',dict(rev(),expected_participant_version=1,confirmation='constructor',is_active=False))
 with sync_playwright() as p:
  browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BINARY','/usr/bin/chromium'),headless=True,args=['--no-sandbox'])
  context=browser.new_context(extra_http_headers={'Authorization':HTTPTests.auth},viewport={'width':1280,'height':1000});page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.on('dialog',lambda d:d.accept())
  detail=HTTPTests.origin+'/gyugyu/event.php?id='+row['id'];manage=HTTPTests.origin+'/gyugyu/participants.php?id='+row['id'];page.goto(detail)
  page.locator('#participant-add').click();page.locator('#participant-name').fill('下書き');page.locator('#participant-manage').dispatch_event('click');assert page.url==detail;page.locator('#answer-cancel').click();page.locator('#participant-manage').click()
  first=page.locator('form[data-participant="'+people[0]['id']+'"]');second=page.locator('form[data-participant="'+people[1]['id']+'"]')
  expect(page.locator('#participant-restoration form')).to_have_count(2)
  for person in people:expect(page.locator('form[data-participant="'+person['id']+'"] legend')).to_contain_text('#'+person['id'][:6])
  first.locator('input').fill('constructor');expect(second.locator('input')).to_be_disabled();first.locator('[type=reset]').click();expect(first.locator('input')).to_have_value('');expect(second.locator('input')).to_be_enabled();assert saved()['participants']==[]
  pattern='**/api/index.php?route=**/participants/*/restore'
  first.locator('input').fill('constructor');page.route(pattern,lambda r:r.abort());first.locator('[type=submit]').click();expect(page.locator('#restore-status')).to_contain_text('通信に失敗');expect(first.locator('input')).to_have_value('constructor');assert saved()['participants']==[];page.unroute(pattern)
  def loss(r):r.fetch();r.abort()
  page.route(pattern,loss);first.locator('[type=submit]').click();expect(page.locator('#restore-status')).to_contain_text('通信に失敗');assert len(saved()['participants'])==1;page.unroute(pattern)
  for _ in range(2):first.locator('[type=submit]').click();expect(page.locator('#restore-status')).to_contain_text('競合');expect(first.locator('input')).to_have_value('constructor')
  page.locator('#restore-reload').click();expect(page.locator('#participant-restoration form')).to_have_count(1)
  second.locator('input').fill('constructor')
  request('/participants/'+people[0]['id']+'/submission','PUT',dict(rev(),expected_participant_version=3,display_name='constructor',answers=[]))
  second.locator('[type=submit]').click();expect(page.locator('#restore-status')).to_contain_text('競合');expect(second.locator('input')).to_have_value('constructor')
  page.locator('#restore-reload').click();second.locator('input').fill('constructor');second.locator('[type=submit]').focus();page.keyboard.press('Enter');expect(page.locator('#restore-status')).to_contain_text('復帰しました')
  page.goto(detail);expect(page.locator('.participant-row')).to_have_count(2)
  for person in people:expect(page.locator('[data-participant="'+person['id']+'"] .participant-edit')).to_contain_text('#'+person['id'][:6])
  assert next(person for person in saved()['participants'] if person['id']==people[1]['id'])['submitted_at']==people[1]['submitted_at']
  request('/participants/'+people[1]['id'],'PATCH',dict(rev(),expected_participant_version=3,confirmation='constructor',is_active=False))
  req=dict(rev(),action='archive',day_id=row['days'][0]['id']);preview=request('/days/preview','POST',req)
  request('/days','DELETE',dict(req,confirmed_operation='archive',confirmation='2026-11-03',expected_answers_revision=preview['answers_revision'],expected_answer_count=preview['answer_count'],expected_visible_answers=preview['visible_answers']))
  mobile=browser.new_context(extra_http_headers={'Authorization':HTTPTests.auth},viewport={'width':390,'height':844},is_mobile=True,has_touch=True);mp=mobile.new_page();mp.on('pageerror',lambda e:errors.append(str(e)));mp.on('dialog',lambda d:d.accept());mp.goto(detail)
  mp.locator('#participant-manage').tap();mp.locator('form input').fill('constructor');mp.locator('form [type=submit]').tap();expect(mp.locator('#restore-status')).to_contain_text('復帰しました');mp.locator('a',has_text='案件に戻る').tap()
  expect(mp.locator('.participant-row')).to_have_count(2);expect(mp.locator('#group-status')).to_contain_text('対象2人');expect(mp.locator('#date-manage')).to_be_visible();expect(mp.locator('#export-download')).to_be_enabled()
  assert mp.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'mobile restoration overflow';assert not errors,errors;browser.close()
 print('PASS: Chromium participant restore IDs, cancel, draft protection, network/lost response, repeated and fresh-answer conflicts, keyboard, zero-day mobile touch')
finally:HTTPTests.tearDownClass()
