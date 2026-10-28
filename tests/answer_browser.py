"""Isolated Chromium participant/answer workflows, including response loss and races."""
import json
import os
from http_test import HTTPTests
from playwright.sync_api import sync_playwright, expect
HTTPTests.setUpClass()
try:
    api=HTTPTests(); _,headers,_=api.request('api/index.php'); token=headers['X-CSRF-Token']
    _,_,body=api.request('api/index.php','POST',{'title':'回答ブラウザー','dates':['2026-11-02','2026-11-03']},token=token)
    row=json.loads(body); route='api/index.php?route=/instances/'+row['id']
    def stored(): return json.loads(api.request(route)[2])
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BINARY','/usr/bin/chromium'),headless=True,args=['--no-sandbox'])
        context=browser.new_context(extra_http_headers={'Authorization':HTTPTests.auth},viewport={'width':1280,'height':1600})
        page=context.new_page(); errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
        dialogs=[]
        def accept(dialog): dialogs.append(dialog.message); dialog.accept()
        page.on('dialog',accept)
        url=HTTPTests.origin+'/gyugyu/event.php?id='+row['id'];page.goto(url)
        def add(name='山田'):
            page.locator('#participant-add').click();page.locator('#participant-name').fill(name)
        def cells(pid='draft',day=0):return page.locator('.day-panel').nth(day).locator(f'.participant-row[data-participant="{pid}"] .answer-cell')
        def key(cell,state):page.locator('#answer-palette').select_option(state);cell.focus();page.keyboard.press('Space')
        def save():page.locator('#answer-save').click();expect(page.locator('#answer-status')).to_have_text('回答の保存完了')
        add('破棄する下書き'); assert stored()['participants']==[]
        page.locator('#answer-cancel').click();assert stored()['participants']==[]
        add(); expect(page.locator('#candidate-edit')).to_be_disabled()
        expect(page.locator('#metadata-editor')).to_be_hidden()
        key(cells().nth(6),'yes');key(cells().nth(7),'maybe');key(cells().nth(8),'no');key(cells().nth(0),'yes')
        expect(cells().nth(0)).to_have_text('—')
        def drag(a,b):
            a.scroll_into_view_if_needed(); x=a.bounding_box();y=b.bounding_box()
            page.mouse.move(x['x']+8,x['y']+13);page.mouse.down();page.mouse.move(y['x']+8,y['y']+13,steps=6)
        page.locator('#answer-palette').select_option('yes')
        drag(cells().nth(9),cells().nth(12));page.mouse.move(1250,20);page.mouse.up()
        b=cells().nth(13).bounding_box();page.mouse.move(b['x']+8,b['y']+13);expect(cells().nth(13)).to_have_text('—')
        page.locator('#answer-undo').click();expect(cells().nth(9)).to_have_text('—')
        page.locator('#answer-redo').click();expect(cells().nth(12)).to_have_text('○')
        drag(cells().nth(13),cells().nth(14));page.dispatch_event('.answer-cell','pointercancel',{'pointerId':1});page.mouse.up()
        expect(cells().nth(13)).to_have_text('—')
        drag(cells().nth(16),cells().nth(16));page.mouse.move(1250,20)
        b=cells().nth(20).bounding_box();page.mouse.move(b['x']+8,b['y']+13);page.mouse.up()
        expect(cells().nth(17)).to_have_text('—');expect(cells().nth(20)).to_have_text('○')
        page.locator('#answer-undo').click()
        page.set_viewport_size({'width':1280,'height':2400})
        drag(cells().nth(16),cells().nth(16))
        b=cells(day=1).nth(6).bounding_box();page.mouse.move(b['x']+8,b['y']+13)
        b=cells().nth(20).bounding_box();page.mouse.move(b['x']+8,b['y']+13);page.mouse.up()
        expect(cells().nth(17)).to_have_text('—');expect(cells(day=1).nth(6)).to_have_text('○')
        page.locator('#answer-undo').click()
        # Failure before submission leaves no partial participant and preserves the entire draft.
        page.route('**/api/index.php?route=**/participants',lambda route:route.abort())
        page.locator('#answer-save').click();expect(page.locator('#answer-status')).to_contain_text('通信に失敗')
        expect(page.locator('#participant-name')).to_have_value('山田');expect(cells().nth(8)).to_have_text('×');assert not stored()['participants']
        page.unroute('**/api/index.php?route=**/participants');save()
        first=stored()['participants'][0];pid=first['id'];assert len(first['answers'])==7
        cells(pid).nth(7).focus();page.keyboard.press('Space');expect(cells(pid).nth(7)).to_have_text('△')
        assert stored()['participants'][0]['version']==first['version']
        add();save();people=stored()['participants'];assert len(people)==2
        second=next(person for person in people if person['id']!=pid)
        assert second['answers']==[] and second['submitted_at']
        assert '#' in page.locator('.participant-edit').first.inner_text()
        assert '提出済2人' in api.request()[2]
        # Lost registration response uses the same public request key on retry, preventing duplicates.
        add('応答喪失')
        def lose(route):route.fetch();route.abort()
        page.route('**/api/index.php?route=**/participants',lose)
        page.locator('#answer-save').click();expect(page.locator('#answer-status')).to_contain_text('通信に失敗');assert len(stored()['participants'])==3
        page.unroute('**/api/index.php?route=**/participants')
        page.locator('#answer-save').click();expect(page.locator('#answer-status')).to_contain_text('競合');assert len(stored()['participants'])==3
        page.locator('#answer-reload').click();expect(page.locator('#participant-add')).to_be_visible()
        # Separate browser context: the shared account can edit any participant, without a PIN.
        otherctx=browser.new_context(extra_http_headers={'Authorization':HTTPTests.auth});other=otherctx.new_page();other.on('dialog',accept);other.goto(url)
        selector=f'.participant-row[data-participant="{pid}"] .participant-edit'
        other.locator(selector).first.click();other.locator('#participant-name').fill('競合下書き')
        page.locator(selector).first.click();key(cells(pid).nth(6),'no');save()
        for _ in range(2):
            other.locator('#answer-save').click();expect(other.locator('#answer-status')).to_contain_text('競合');expect(other.locator('#participant-name')).to_have_value('競合下書き');expect(other.locator('#answer-status')).to_contain_text('最新版との差')
        other.locator('#answer-cancel').click();otherctx.close()
        page.locator(selector).first.click();key(cells(pid).nth(7),'unknown');save()
        assert all(answer['slot_id']!=row['days'][0]['slots'][7]['id'] for answer in next(p for p in stored()['participants'] if p['id']==pid)['answers'])
        # Lost response to an existing participant PUT keeps the old expected version.
        page.locator(selector).first.click();page.locator('#participant-name').fill('応答後も保持する下書き')
        page.route('**/api/index.php?route=**/submission',lose)
        page.locator('#answer-save').click();expect(page.locator('#answer-status')).to_contain_text('通信に失敗')
        page.unroute('**/api/index.php?route=**/submission')
        page.locator('#answer-save').click();expect(page.locator('#answer-status')).to_contain_text('競合')
        expect(page.locator('#participant-name')).to_have_value('応答後も保持する下書き')
        page.locator('#answer-reload').click();expect(page.locator('#participant-add')).to_be_visible()
        # Candidate preview must detect a response submitted after confirmation was calculated.
        page.locator('#candidate-edit').click();page.locator('#candidate-palette').select_option('false')
        c=page.locator('.candidate-cell').nth(6);c.focus();page.keyboard.press('Space')
        page.remove_listener('dialog',accept)
        def race(dialog):
            dialogs.append(dialog.message)
            data={'expected_version':1,'expected_schema_revision':1,'expected_participant_version':second['version'],'display_name':second['display_name'],'answers':[{'slot_id':row['days'][0]['slots'][6]['id'],'state':'yes'}]}
            assert api.request(route+'/participants/'+second['id']+'/submission','PUT',data,token=token)[0]==200
            dialog.accept()
        page.once('dialog',race);page.locator('#candidate-save').click();expect(page.locator('#candidate-status')).to_contain_text('競合')
        assert '隠れる回答1件' in dialogs[-1] and stored()['schema_revision']==1
        page.on('dialog',accept);page.locator('#candidate-save').click();expect(page.locator('#candidate-status')).to_have_text('保存完了')
        assert '隠れる回答2件' in dialogs[-1]
        # Disabled answers cannot be changed or cleared by a full submission.
        page.locator(selector).first.click();key(cells(pid).nth(6),'yes');expect(cells(pid).nth(6)).to_have_text('×')
        page.locator('#participant-name').fill('改名後');save()
        assert any(a['slot_id']==row['days'][0]['slots'][6]['id'] and a['state']=='no' for a in next(p for p in stored()['participants'] if p['id']==pid)['answers'])
        page.locator('#candidate-edit').click();page.locator('#candidate-palette').select_option('true');c.focus();page.keyboard.press('Space')
        page.locator('#candidate-save').click();expect(page.locator('#candidate-status')).to_have_text('保存完了')
        expect(cells(pid).nth(6)).to_have_text('×')
        # Hide is confirmed, versioned, and retains answers in storage.
        page.locator(selector).first.click();page.remove_listener('dialog',accept)
        page.once('dialog',lambda dialog:dialog.dismiss())
        page.locator('#participant-hide').click()
        assert any(person['id']==pid for person in stored()['participants'])
        page.once('dialog',lambda dialog:dialog.accept('改名後'))
        page.locator('#participant-hide').click();expect(page.locator('#answer-status')).to_contain_text('非表示にしました')
        assert all(person['id']!=pid for person in stored()['participants'])
        page.on('dialog',accept)
        add('😀'*81);before_invalid=len(stored()['participants'])
        page.locator('#answer-save').click();assert len(stored()['participants'])==before_invalid
        assert not page.locator('#participant-name').evaluate('(el)=>el.checkValidity()')
        page.locator('#participant-name').fill('😀'*80);save()
        assert any(person['display_name']=='😀'*80 for person in stored()['participants'])
        assert not errors,errors
        # Arbitrary names must not collide with JavaScript object prototype properties.
        duplicate_names=['constructor','__proto__','toString']
        for name in duplicate_names:
            add(name);save();add(name);save()
        def check_duplicate_labels():
            people=stored()['participants']
            for name in duplicate_names:
                matching=[person for person in people if person['display_name']==name]
                assert len(matching)==2
                for person in matching:
                    buttons=page.locator(f'.participant-row[data-participant="{person["id"]}"] .participant-edit')
                    expect(buttons).to_have_text([name+' #'+person['id'][:6]]*2)
        check_duplicate_labels()
        page.locator('#metadata-editor summary').click();page.locator('#metadata-editor summary').click()
        check_duplicate_labels()
        page.reload();expect(page.locator('#participant-add')).to_be_visible()
        check_duplicate_labels()
        mobile=browser.new_context(extra_http_headers={'Authorization':HTTPTests.auth},viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
        touch=mobile.new_page();touch.on('dialog',accept);touch.goto(url)
        touch.locator('.participant-edit').first.click();touch.locator('#answer-palette').select_option('maybe')
        tcells=touch.locator('.participant-row.answer-editing').first.locator('.answer-cell');tcells.nth(6).tap();expect(tcells.nth(6)).to_have_text('△')
        tcells.nth(6).scroll_into_view_if_needed();a=tcells.nth(6).bounding_box();b=tcells.nth(9).bounding_box();cdp=mobile.new_cdp_session(touch)
        cdp.send('Input.dispatchTouchEvent',{'type':'touchStart','touchPoints':[{'x':a['x']+8,'y':a['y']+13}]})
        cdp.send('Input.dispatchTouchEvent',{'type':'touchMove','touchPoints':[{'x':b['x']+8,'y':b['y']+13}]})
        cdp.send('Input.dispatchTouchEvent',{'type':'touchEnd','touchPoints':[]})
        expect(tcells.nth(9)).to_have_text('△');touch.locator('#answer-undo').click();expect(tcells.nth(9)).to_have_text('—')
        assert tcells.nth(0).bounding_box()['width']==16
        assert touch.evaluate('document.documentElement.scrollWidth <= innerWidth')
        touch.locator('#answer-cancel').click();touch.screenshot(path='/tmp/gyugyu-answers-mobile.png',full_page=True);mobile.close();browser.close()
        # SPEC 15.5: close the browser process, then read every saved answer in a new session.
        persisted=stored()
        browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BINARY','/usr/bin/chromium'),headless=True,args=['--no-sandbox'])
        reopened=browser.new_page(extra_http_headers={'Authorization':HTTPTests.auth});reopened.goto(url)
        for person in persisted['participants']:
            values={a['slot_id']:a['state'] for a in person['answers']}
            for day_index,day in enumerate(persisted['days']):
                actual=reopened.locator('.day-panel').nth(day_index).locator(f'.participant-row[data-participant="{person["id"]}"] .answer-cell')
                expect(actual).to_have_text([{'yes':'○','maybe':'△','no':'×','unknown':'—'}[values.get(slot['id'],'unknown')] for slot in day['slots']])
        assert stored()==persisted
        browser.close()
    print('PASS: Chromium participant registration, shared editing, answers, unknown, drag/cancel, failure/lost response, conflict, actual exclusion race/history, hide, keyboard/touch/mobile, browser-process restart persistence')
finally:
    HTTPTests.tearDownClass()
