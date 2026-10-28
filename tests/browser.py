"""Optional real-browser CRUD smoke check. Requires Python Playwright and Chromium."""
import os
from http_test import HTTPTests
from playwright.sync_api import sync_playwright, expect

HTTPTests.setUpClass()
try:
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BINARY','/usr/bin/chromium'),headless=True,args=['--no-sandbox'])
        # Reuse the test server with an Authorization header; no app-side bypass.
        page=browser.new_page(extra_http_headers={'Authorization':HTTPTests.auth})
        page.goto(HTTPTests.origin+'/gyugyu/')
        page.get_by_role('link',name='＋ 新しいスケジュール調整').click()
        page.get_by_label('スケジュール調整名').fill('ブラウザー検証')
        page.get_by_label('説明（任意・複数行）').fill('詳細のみの説明\n<script>alert(1)</script>')
        page.get_by_label('候補日（').fill('2026-11-02\n2026-11-02\n2026-11-04')
        page.get_by_role('button',name='プレビュー',exact=True).click()
        assert page.get_by_text('重複した日付を1件まとめました。').is_visible()
        page.get_by_role('button',name='作成',exact=True).click()
        assert page.get_by_role('heading',name='ブラウザー検証',exact=True).is_visible()
        assert page.locator('.description').text_content()=='詳細のみの説明\n<script>alert(1)</script>', repr(page.locator('.description').text_content())
        page.get_by_text('調整名・説明を編集',exact=True).click()
        page.get_by_label('スケジュール調整名').fill('変更済み')
        page.get_by_label('説明（任意・複数行）').fill('')
        page.get_by_role('button',name='保存',exact=True).click()
        expect(page.get_by_role('heading',name='変更済み',exact=True)).to_be_visible()
        assert page.locator('.description').count()==0
        page.get_by_role('link',name='一覧へ戻る').click()
        assert '詳細のみの説明' not in page.content()
        page.get_by_role('link',name='削除',exact=True).click()
        page.get_by_label('確認のため案件名を再入力').fill('間違い')
        page.get_by_role('button',name='論理削除する').click()
        expect(page.get_by_role('alert')).to_be_visible()
        page.get_by_label('確認のため案件名を再入力').fill('変更済み')
        page.get_by_role('button',name='論理削除する').click()
        assert page.get_by_role('link',name='変更済み',exact=True).count()==0
        page.get_by_role('link',name='削除済みを表示／復元').click()
        page.get_by_role('button',name='復元',exact=True).click()
        expect(page.get_by_role('link',name='変更済み',exact=True)).to_be_visible()
        page.set_viewport_size({'width':390,'height':844})
        assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth')
        page.locator('header img').evaluate('(img) => img.decode()')
        assert page.locator('header img').evaluate('(img) => img.naturalWidth > 0')
        page.screenshot(path='/tmp/gyugyu-portal-mobile.png',full_page=True)
        browser.close()
    print('PASS: browser create/preview/edit/delete/restore and mobile portal')
finally:
    HTTPTests.tearDownClass()
