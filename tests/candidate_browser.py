"""Real Chromium interaction checks for candidate masks, using isolated HTTP fixtures."""
import json
import os
import sqlite3
from http_test import HTTPTests
from playwright.sync_api import sync_playwright, expect

HTTPTests.setUpClass()
try:
    client=HTTPTests()
    _,headers,_=client.request('api/index.php')
    status,_,body=client.request('api/index.php','POST',{'title':'候補グリッド','dates':['2026-11-02','2026-11-03']},token=headers['X-CSRF-Token'])
    assert status==201
    row=json.loads(body)
    route='api/index.php?route=/instances/'+row['id']
    def stored(): return json.loads(client.request(route)[2])
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BINARY','/usr/bin/chromium'),headless=True,args=['--no-sandbox'])
        context=browser.new_context(extra_http_headers={'Authorization':HTTPTests.auth},viewport={'width':1280,'height':1000})
        page=context.new_page(); errors=[]; page.on('pageerror',lambda error:errors.append(str(error)))
        dialogs=[]
        def accept(dialog): dialogs.append(dialog.message); dialog.accept()
        page.on('dialog',accept)
        url=HTTPTests.origin+'/gyugyu/event.php?id='+row['id']
        page.goto(url)
        grids=page.locator('.day-grid'); cells=page.locator('.day-panel').nth(0).locator('.candidate-cell')
        expect(grids).to_have_count(2); expect(cells).to_have_count(48)
        assert grids.nth(1).bounding_box()['y'] > grids.nth(0).bounding_box()['y']
        assert grids.nth(0).locator('thead th[colspan="2"]').count()==24
        assert '24' in grids.nth(0).locator('thead').inner_text()
        assert cells.nth(0).bounding_box()['width']==16
        assert abs(grids.nth(0).bounding_box()['width']-916)<1
        expect(cells.nth(6)).to_be_enabled()
        mutations=[]
        page.on('request',lambda request:mutations.append(request.url) if request.method=='PATCH' else None)
        # Keyboard focus, activation and clicks inspect exact half-hour times only.
        cells.nth(6).focus(); page.keyboard.press('Tab')
        expect(cells.nth(7)).to_be_focused()
        expect(page.locator('#candidate-inspect')).to_have_text('2026-11-02 09:30〜10:00 候補（日本時間）')
        page.keyboard.press('Space'); page.keyboard.press('Enter')
        cells.nth(0).click()
        expect(page.locator('#candidate-inspect')).to_have_text('2026-11-02 06:00〜06:30 候補外（日本時間）')
        expect(cells.nth(0)).to_have_attribute('aria-pressed','false')
        expect(cells.nth(7)).to_have_attribute('aria-pressed','true')
        assert not mutations and stored()==row
        page.locator('#metadata-editor summary').click(); expect(page.locator('#candidate-edit')).to_be_disabled()
        page.locator('#metadata-editor summary').click()
        page.locator('#candidate-edit').click(); expect(page.locator('#metadata-editor')).to_be_hidden()
        page.locator('#candidate-palette').select_option('false')
        def drag(first,last):
            first.scroll_into_view_if_needed(); a=first.bounding_box(); b=last.bounding_box()
            page.mouse.move(a['x']+a['width']/2,a['y']+13); page.mouse.down()
            page.mouse.move(b['x']+b['width']/2,b['y']+13,steps=8)
        # Leaving the row while still pressed breaks interpolation on re-entry.
        drag(cells.nth(6),cells.nth(6))
        page.mouse.move(1200,20)
        b=cells.nth(20).bounding_box(); page.mouse.move(b['x']+8,b['y']+13); page.mouse.up()
        for i in range(7,20): expect(cells.nth(i)).to_have_attribute('aria-pressed','true')
        for i in [6,20]: expect(cells.nth(i)).to_have_attribute('aria-pressed','false')
        page.locator('#candidate-undo').click()
        # Different day panels also break interpolation, even when returning to day 1.
        page.set_viewport_size({'width':1280,'height':2400})
        drag(cells.nth(6),cells.nth(6))
        second=page.locator('.day-panel').nth(1).locator('.candidate-cell')
        b=second.nth(8).bounding_box(); page.mouse.move(b['x']+8,b['y']+13)
        b=cells.nth(20).bounding_box(); page.mouse.move(b['x']+8,b['y']+13); page.mouse.up()
        expect(second.nth(8)).to_have_attribute('aria-pressed','false')
        for i in range(7,20): expect(cells.nth(i)).to_have_attribute('aria-pressed','true')
        expect(second.nth(9)).to_have_attribute('aria-pressed','true')
        page.locator('#candidate-undo').click()
        expect(second.nth(8)).to_have_attribute('aria-pressed','true')
        page.set_viewport_size({'width':1280,'height':1000})
        drag(cells.nth(6),cells.nth(9))
        page.mouse.move(1200,20); page.mouse.up()
        for i in range(6,10): expect(cells.nth(i)).to_have_attribute('aria-pressed','false')
        a=cells.nth(10).bounding_box(); page.mouse.move(a['x']+8,a['y']+13)
        expect(cells.nth(10)).to_have_attribute('aria-pressed','true')
        page.locator('#candidate-undo').click()
        for i in range(6,10): expect(cells.nth(i)).to_have_attribute('aria-pressed','true')
        page.locator('#candidate-redo').click()
        # A canceled pointer gesture rolls back only that stroke.
        drag(cells.nth(10),cells.nth(11))
        page.dispatch_event('.candidate-cell','pointercancel',{'pointerId':1})
        page.mouse.up()
        expect(cells.nth(10)).to_have_attribute('aria-pressed','true')
        expect(cells.nth(6)).to_have_attribute('aria-pressed','false')
        assert stored()['schema_revision']==1
        page.remove_listener('dialog',accept)
        page.once('dialog',lambda dialog:dialog.dismiss())
        page.locator('#candidate-cancel').click()
        expect(page.locator('#candidate-tools')).to_be_visible()
        expect(cells.nth(6)).to_have_attribute('aria-pressed','false')
        page.on('dialog',accept)
        page.locator('#candidate-cancel').click()
        expect(cells.nth(6)).to_have_attribute('aria-pressed','true')
        page.locator('#candidate-edit').click()
        # Selected-day bulk replacement previews exclusions before changing the draft.
        page.locator('#candidate-scope').select_option('selected')
        page.locator('#candidate-days input').nth(0).check()
        page.locator('#candidate-start').fill('22:00'); page.locator('#candidate-end').fill('06:00'); page.locator('#candidate-next').check()
        page.locator('#candidate-bulk').click()
        assert '対象1日' in dialogs[-1] and '既存回答数0件' in dialogs[-1]
        expect(cells.nth(32)).to_have_attribute('aria-pressed','true')
        expect(page.locator('.day-panel').nth(1).locator('.candidate-cell').nth(32)).to_have_attribute('aria-pressed','false')
        assert stored()['schema_revision']==1
        # Network failure retains draft, and retry with the original revision succeeds.
        page.route('**/api/index.php?route=**',lambda route:route.abort() if route.request.method=='PATCH' else route.continue_())
        page.locator('#candidate-save').click()
        expect(page.locator('#candidate-status')).to_contain_text('通信に失敗')
        expect(cells.nth(32)).to_have_attribute('aria-pressed','true')
        assert stored()['schema_revision']==1
        page.unroute('**/api/index.php?route=**')
        page.locator('#candidate-save').click(); expect(page.locator('#candidate-status')).to_have_text('保存完了')
        assert stored()['schema_revision']==2
        # Two independently loaded tabs; repeated stale save remains a conflict.
        other=context.new_page(); other.on('dialog',accept); other.goto(url)
        other.locator('#candidate-edit').click(); other.locator('#candidate-palette').select_option('false')
        other.locator('.candidate-cell').nth(32).focus(); other.keyboard.press('Space')
        page.locator('#candidate-edit').click(); page.locator('#candidate-palette').select_option('true')
        cells.nth(0).focus(); page.keyboard.press('Space')
        page.locator('#candidate-save').click(); expect(page.locator('#candidate-status')).to_have_text('保存完了')
        for _ in range(2):
            other.locator('#candidate-save').click()
            expect(other.locator('#candidate-status')).to_contain_text('競合しました')
            expect(other.locator('#candidate-status')).to_contain_text('最新版との差')
            expect(other.locator('.candidate-cell').nth(32)).to_have_attribute('aria-pressed','false')
        assert stored()['schema_revision']==3
        other.locator('#candidate-cancel').click(); other.close()
        # A response lost after the server commits must not silently overwrite on retry.
        page.locator('#candidate-edit').click(); cells.nth(1).focus(); page.keyboard.press('Space')
        def lose_response(route):
            if route.request.method=='PATCH': route.fetch(); route.abort()
            else: route.continue_()
        page.route('**/api/index.php?route=**',lose_response)
        page.locator('#candidate-save').click(); expect(page.locator('#candidate-status')).to_contain_text('通信に失敗')
        assert stored()['schema_revision']==4
        page.unroute('**/api/index.php?route=**')
        page.locator('#candidate-save').click(); expect(page.locator('#candidate-status')).to_contain_text('競合しました')
        assert stored()['schema_revision']==4
        page.locator('#candidate-reload').click(); expect(page.locator('#candidate-status')).to_have_text('閲覧中')
        expect(page.locator('#candidate-edit')).to_be_visible()
        # Density and mobile horizontal scroll preserve exact widths and sticky names.
        for density in [20,24,16]:
            page.locator('#grid-density').select_option(str(density))
            assert cells.nth(0).bounding_box()['width']==density, (density,cells.nth(0).bounding_box(),page.locator('#candidate-grid').get_attribute('data-density'))
        page.set_viewport_size({'width':390,'height':844})
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
        scroll=page.locator('.day-scroll').nth(0)
        scroll.evaluate('(el)=>el.scrollLeft=450')
        assert scroll.evaluate('(el)=>el.scrollLeft')>0
        assert abs(grids.nth(0).locator('tbody th').bounding_box()['x']-scroll.bounding_box()['x'])<1
        assert cells.nth(0).bounding_box()['width']==16
        assert not errors, errors
        page.screenshot(path='/tmp/gyugyu-candidate-mobile.png',full_page=True)
        mobile=browser.new_context(extra_http_headers={'Authorization':HTTPTests.auth},viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
        touch=mobile.new_page(); touch.on('dialog',accept); touch.goto(url)
        expect(touch.locator('#candidate-edit')).to_be_visible()
        before_tap=stored()
        touch.locator('.candidate-cell').nth(7).tap()
        expect(touch.locator('#candidate-inspect')).to_have_text('2026-11-02 09:30〜10:00 候補外（日本時間）')
        assert stored()==before_tap
        strip=touch.locator('.day-scroll').nth(0); strip.scroll_into_view_if_needed()
        box=strip.bounding_box(); cdp=mobile.new_cdp_session(touch)
        y=touch.locator('.day-grid thead').nth(0).bounding_box()['y']+12
        cdp.send('Input.dispatchTouchEvent',{'type':'touchStart','touchPoints':[{'x':box['x']+box['width']-15,'y':y}]})
        for offset in [40,80,120]:
            cdp.send('Input.dispatchTouchEvent',{'type':'touchMove','touchPoints':[{'x':box['x']+box['width']-15-offset,'y':y}]})
            touch.wait_for_timeout(40)
        cdp.send('Input.dispatchTouchEvent',{'type':'touchEnd','touchPoints':[]})
        touch.wait_for_function("document.querySelector('.day-scroll').scrollLeft > 0")
        touch.locator('#candidate-edit').click(); touch.locator('#candidate-palette').select_option('true')
        strip.evaluate('(el)=>el.scrollLeft=0')
        tcells=touch.locator('.day-panel').nth(0).locator('.candidate-cell')
        tcells.nth(6).scroll_into_view_if_needed()
        a=tcells.nth(6).bounding_box(); b=tcells.nth(9).bounding_box()
        cdp.send('Input.dispatchTouchEvent',{'type':'touchStart','touchPoints':[{'x':a['x']+8,'y':a['y']+13}]})
        cdp.send('Input.dispatchTouchEvent',{'type':'touchMove','touchPoints':[{'x':b['x']+8,'y':b['y']+13}]})
        cdp.send('Input.dispatchTouchEvent',{'type':'touchEnd','touchPoints':[]})
        for i in range(6,10): expect(tcells.nth(i)).to_have_attribute('aria-pressed','true')
        touch.locator('#candidate-undo').click()
        for i in range(6,10): expect(tcells.nth(i)).to_have_attribute('aria-pressed','false')
        touch.locator('#candidate-cancel').click()
        assert stored()['schema_revision']==4
        # Read-only lifecycle states still permit inspection, with no edit control.
        for state in ['locked','confirmed']:
            with sqlite3.connect(HTTPTests.env['GYUGYU_DB']) as db:
                db.execute('UPDATE instances SET status=? WHERE id=?',(state,row['id']))
            touch.goto(url)
            expect(touch.locator('#candidate-edit')).to_have_count(0)
            touch.locator('.candidate-cell').nth(7).tap()
            expect(touch.locator('#candidate-inspect')).to_have_text('2026-11-02 09:30〜10:00 候補外（日本時間）')
            assert stored()['schema_revision']==4
        mobile.close()
        browser.close()
    print('PASS: Chromium read-only keyboard/tap inspection, grid density, bulk/drag, outside re-entry, cross-day drag, pointer cancel, undo/redo, cancel, network failure, lost response, repeated conflict, mobile touch/scroll')
finally:
    HTTPTests.tearDownClass()
