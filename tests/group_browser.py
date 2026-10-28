"""Group management, memberships and filter integration in isolated Chromium."""
import json, os
from http_test import HTTPTests
from playwright.sync_api import sync_playwright, expect
HTTPTests.setUpClass()
try:
    api=HTTPTests(); _,h,_=api.request('api/index.php');token=h['X-CSRF-Token']
    _,_,body=api.request('api/index.php','POST',{'title':'グループUI','dates':['2026-11-02','2026-11-03']},token=token)
    row=json.loads(body);route='api/index.php?route=/instances/'+row['id']
    def stored():return json.loads(api.request(route)[2])
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BINARY','/usr/bin/chromium'),headless=True,args=['--no-sandbox'])
        context=browser.new_context(extra_http_headers={'Authorization':HTTPTests.auth},viewport={'width':1280,'height':1800})
        page=context.new_page();errors=[];page.on('pageerror',lambda error:errors.append(str(error)));page.on('dialog',lambda dialog:dialog.accept())
        detail=HTTPTests.origin+'/gyugyu/event.php?id='+row['id'];manage=HTTPTests.origin+'/gyugyu/groups.php?id='+row['id']
        page.goto(detail);expect(page.locator('#group-filter')).to_be_hidden()
        page.locator('#group-manage').click()
        def create(name):
            page.wait_for_load_state('load')
            f=page.locator('form[data-action=create]');f.locator('[name=name]').fill(name);f.locator('button:not([type=reset])').click();expect(f.locator('[name=name]')).to_have_value('');page.wait_for_load_state('load')
        create('constructor');create('<img src=x onerror=alert(1)>');groups=stored()['groups'];a,b=groups
        assert len(groups)==2
        # Keep form contents and versions on transport failure; result loss retries conflict.
        f=page.locator('form[data-action=create]');f.locator('[name=name]').fill('通信')
        page.route('**/groups.php?*',lambda r:r.abort() if r.request.method=='POST' else r.continue_())
        f.locator('button:not([type=reset])').click();expect(page.locator('#group-save-status')).to_contain_text('通信に失敗');expect(f.locator('[name=name]')).to_have_value('通信');assert len(stored()['groups'])==2
        page.unroute('**/groups.php?*')
        def loss(r):
            if r.request.method=='POST':r.fetch();r.abort()
            else:r.continue_()
        page.route('**/groups.php?*',loss);f.locator('button:not([type=reset])').click();expect(page.locator('#group-save-status')).to_contain_text('通信に失敗');assert len(stored()['groups'])==3
        page.unroute('**/groups.php?*');f.locator('button:not([type=reset])').click();expect(page.locator('#group-save-status')).to_contain_text('更新されました');assert len(stored()['groups'])==3
        page.goto(detail)
        def add(name,ids):
            page.locator('#participant-add').click();page.locator('#participant-name').fill(name)
            for id in ids:page.locator(f'#participant-groups input[value="{id}"]').check()
            expect(page.locator('#group-mode')).to_be_disabled()
            page.locator('#group-manage').dispatch_event('click');assert page.url==detail
            page.locator('#answer-save').click();expect(page.locator('#answer-status')).to_have_text('回答の保存完了')
        add('両方',[a['id'],b['id']]);add('片方',[a['id']]);add('未所属',[])
        first=next(person for person in stored()['participants'] if person['display_name']=='両方')
        page.locator(f'.participant-row[data-participant="{first["id"]}"] .participant-edit').first.click()
        page.locator(f'#participant-groups input[value="{b["id"]}"]').uncheck()
        page.route('**/api/index.php?route=**/submission',lambda r:r.abort())
        page.locator('#answer-save').click();expect(page.locator('#answer-status')).to_contain_text('通信に失敗')
        expect(page.locator(f'#participant-groups input[value="{b["id"]}"]')).not_to_be_checked()
        assert len(next(person for person in stored()['participants'] if person['id']==first['id'])['group_ids'])==2
        page.unroute('**/api/index.php?route=**/submission');page.locator('#answer-cancel').click()

        page.locator('#group-mode').select_option('include');expect(page.locator('#group-status')).to_contain_text('対象0人');expect(page.locator('#group-status')).to_contain_text('グループ未選択')
        page.locator(f'#group-filter input[value="{a["id"]}"]').check();expect(page.locator('.day-panel').first.locator('.participant-row:visible')).to_have_count(2)
        page.locator(f'#group-filter input[value="{b["id"]}"]').check();expect(page.locator('#group-status')).to_contain_text('対象2人')
        page.locator('#group-mode').select_option('exclude');expect(page.locator('#group-status')).to_contain_text('対象1人')
        page.locator(f'#group-filter input[value="{a["id"]}"]').uncheck();page.locator(f'#group-filter input[value="{b["id"]}"]').uncheck();expect(page.locator('#group-status')).to_contain_text('対象3人')
        page.locator('#group-mode').select_option('include');page.locator(f'#group-filter input[value="{a["id"]}"]').check()
        page.goto(manage)
        # Two-screen conflict retains edited group name.
        second=context.new_page();second.goto(manage)
        edit=page.locator(f'[data-group="{b["id"]}"] form[data-action=edit]');edit.locator('[name=description]').fill('説明変更');edit.locator('button:not([type=reset])').click();expect(edit.locator('[name=description]')).to_have_value('説明変更')
        expect(page.locator('#group-save-status')).to_have_text('')
        stale=second.locator(f'[data-group="{b["id"]}"] form[data-action=edit]');stale.locator('[name=name]').fill('競合下書き');stale.locator('button:not([type=reset])').click();expect(second.locator('#group-save-status')).to_contain_text('更新されました');expect(stale.locator('[name=name]')).to_have_value('競合下書き');second.close()
        archive=page.locator(f'[data-group="{a["id"]}"] form[data-action=archive]');expect(archive).to_contain_text('2人');archive.locator('[name=confirmation]').fill(a['name']);archive.locator('button:not([type=reset])').click();expect(page.locator(f'[data-group="{a["id"]}"]')).to_have_count(0)
        page.goto(detail);expect(page.locator('#group-mode')).to_have_value('include');expect(page.locator('#group-status')).to_contain_text('対象0人')
        page.goto(manage);page.locator('form[data-action=restore] button:not([type=reset])').click();expect(page.locator(f'[data-group="{a["id"]}"]')).to_have_count(1)
        assert sum(a['id'] in person['group_ids'] for person in stored()['participants'])==2
        for i in range(6):create('追加'+str(i))
        expect(page.locator('form[data-action=create] button:not([type=reset])')).to_be_disabled()
        page.goto(detail);page.set_viewport_size({'width':390,'height':844})
        expect(page.locator('#group-filter')).to_be_visible();assert page.locator('.candidate-cell').first.bounding_box()['width']==16
        assert page.locator('.day-scroll').first.evaluate('(e)=>e.scrollWidth>e.clientWidth')
        # Archive every group: zero active groups overrides stored include mode.
        for group in stored()['groups']:
            current=stored()
            data={'expected_version':current['version'],'expected_schema_revision':current['schema_revision'],'expected_answers_revision':current['answers_revision'],'expected_group_version':group['version'],'confirmation':group['name']}
            assert api.request(route+'/groups/'+group['id'],'DELETE',data,token=token)[0]==200
        page.reload();expect(page.locator('#group-filter')).to_be_hidden();expect(page.locator('#group-status')).to_contain_text('対象3人')
        assert not errors,errors
        browser.close()
    print('PASS: Chromium group forms, loss/conflict, memberships, OR/empty/deleted filters, limit and mobile')
finally:HTTPTests.tearDownClass()
