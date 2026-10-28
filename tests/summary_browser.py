"""Summary counts, shared target set, saved drafts, details and fixed density."""
import json,os
from http_test import HTTPTests
from playwright.sync_api import sync_playwright,expect
HTTPTests.setUpClass()
try:
 api=HTTPTests();_,h,_=api.request('api/index.php');token=h['X-CSRF-Token']
 _,_,body=api.request('api/index.php','POST',{'title':'集計','dates':['2026-11-02','2026-11-03']},token=token);row=json.loads(body);route='api/index.php?route=/instances/'+row['id']
 _,_,body=api.request(route+'/groups','POST',{'expected_version':1,'expected_schema_revision':1,'name':'team'},token=token);group=json.loads(body)['id']
 for i,state in enumerate(['yes','no','unknown']):
  payload={'expected_version':1,'expected_schema_revision':2,'display_name':'<name>'+str(i),'submission_key':str(i)*32,'group_ids':[group] if i<2 else [],'answers':[] if state=='unknown' else [{'slot_id':row['days'][0]['slots'][6]['id'],'state':state}]}
  assert api.request(route+'/participants','POST',payload,token=token)[0]==201
 with sync_playwright() as p:
  browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BINARY','/usr/bin/chromium'),headless=True,args=['--no-sandbox'])
  page=browser.new_page(extra_http_headers={'Authorization':HTTPTests.auth},viewport={'width':1280,'height':1600});errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.on('dialog',lambda d:d.accept())
  page.goto(HTTPTests.origin+'/gyugyu/event.php?id='+row['id'])
  panel=page.locator('.day-panel').first
  expect(panel.locator('.summary-row')).to_have_count(3)
  def cell(state='yes',i=6):return panel.locator(f'.summary-cell[data-state="{state}"]').nth(i)
  expect(cell()).to_have_text('1');expect(cell('no')).to_have_text('1');expect(cell('maybe')).to_have_text('0')
  cell().focus();page.keyboard.press('Space');expect(page.locator('#summary-detail')).to_contain_text('対象3人／未入力1人');expect(page.locator('#summary-detail')).to_contain_text('暫定');expect(page.locator('#summary-detail')).to_contain_text('<name>2')
  page.locator('#group-mode').select_option('include');expect(cell()).to_have_text('—');expect(page.locator('#summary-ranking li')).to_have_count(0)
  page.locator('#summary-mode').select_option('all');expect(panel.locator('.participant-row:visible')).to_have_count(3);expect(panel.locator('.outside-mask')).to_have_count(3);expect(cell()).to_have_text('—')
  page.locator(f'#group-filter input[value="{group}"]').check();expect(cell()).to_have_text('1');expect(page.locator('#summary-detail')).to_contain_text('対象2人／未入力0人')
  expect(page.locator('#summary-ranking li').nth(0)).to_contain_text('1位');expect(page.locator('#summary-ranking li').nth(1)).to_contain_text('1位')
  page.locator('#summary-mode').select_option('summary');expect(panel.locator('.participant-row:visible')).to_have_count(0)
  cell('no').focus();page.keyboard.press('Space');expect(page.locator('#summary-detail')).to_contain_text('<name>1');expect(page.locator('#summary-detail')).not_to_contain_text('<name>2')
  before=json.loads(api.request(route)[2])
  page.locator('#summary-mode').select_option('target');expect(panel.locator('.participant-row:visible')).to_have_count(2)
  panel.locator('.participant-row:visible .participant-edit').first.click();expect(page.locator('#summary-mode')).to_be_disabled()
  page.locator('#answer-palette').select_option('maybe');editing=panel.locator('.answer-editing .answer-cell').nth(6);editing.focus();page.keyboard.press('Space')
  expect(cell('maybe')).to_have_text('0');expect(page.locator('#summary-source')).to_contain_text('下書きは含みません')
  page.locator('#answer-save').click();expect(page.locator('#answer-status')).to_have_text('回答の保存完了');expect(cell('maybe')).to_have_text('1')
  # Summary rows stay below newly registered participant rows.
  page.locator('#participant-add').click();expect(panel.locator('tbody tr').last).to_have_class('summary-row');page.locator('#answer-cancel').click()
  page.locator('#candidate-edit').click();page.locator('#candidate-palette').select_option('false');candidate=panel.locator('.candidate-cell').nth(6);candidate.focus();page.keyboard.press('Space');expect(cell('maybe')).to_have_text('1')
  page.locator('#candidate-save').click();expect(page.locator('#candidate-status')).to_contain_text('保存');expect(cell('maybe')).to_have_text('')
  assert row['days'][0]['slots'][6]['id'] not in json.loads(api.request(route+'/summary')[2])['ranking']
  page.set_viewport_size({'width':390,'height':844});assert cell().bounding_box()['width']==16;assert panel.locator('.day-scroll').evaluate('(e)=>e.scrollWidth>e.clientWidth')
  cell('yes',7).focus();page.keyboard.press('Space');expect(page.locator('#summary-detail')).to_contain_text('未入力2人')
  page.set_viewport_size({'width':1280,'height':1600})
  panel.locator('.participant-row:visible .participant-edit').first.click();page.locator(f'#participant-groups input[value="{group}"]').uncheck();page.locator('#answer-save').click();expect(page.locator('#answer-status')).to_have_text('回答の保存完了')
  expect(page.locator('#group-status')).to_contain_text('対象1人');expect(panel.locator('.participant-row:visible')).to_have_count(1)
  assert json.loads(api.request(route+'/summary&mode=include&group_ids[]='+group)[2])['N']==1
  touch=browser.new_page(extra_http_headers={'Authorization':HTTPTests.auth},viewport={'width':390,'height':844},has_touch=True)
  touch.goto(HTTPTests.origin+'/gyugyu/event.php?id='+row['id']);touch.locator('#summary-mode').select_option('summary')
  touch.locator('.day-panel').first.locator('.summary-cell[data-state="yes"]').nth(7).tap();expect(touch.locator('#summary-detail')).to_contain_text('対象3人／未入力3人');touch.close()
  assert not errors,errors
  browser.close()
 print('PASS: Chromium three summary rows, shared mask/modes, details, provisional, saved-only drafts, refresh and mobile')
finally:HTTPTests.tearDownClass()
