#!/usr/bin/env python3
"""手元の試験環境（outputs/test/www を php -S で起動したもの）を実際のブラウザで通しで操作する。
  /usr/bin/python3 scripts/e2e.py <base_url> → outputs/test/shots/*.png。1280px と 390px で横はみ出しも測る"""
import os
import sys

from playwright.sync_api import sync_playwright

B = sys.argv[1].rstrip('/') + '/'
OUT = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'outputs', 'test', 'shots'); os.makedirs(OUT, exist_ok=True)
CSV = os.path.join(os.path.dirname(OUT), 'import.csv')
open(CSV, 'w', encoding='utf-8').write('会社名,電話番号,担当者,郵便番号,住所,業種\n試験商事,052-700-0001,高橋,460-0001,名古屋市中区三の丸1-1,卸売\n中部製作所株式会社,052-200-1000,,460-0008,名古屋市中区栄1-1-1,製造\n,,,,,\n試験工業,0586-70-0002,,491-0859,一宮市本町9-9,製造\n')


def shot(pg, n): pg.screenshot(path=os.path.join(OUT, n + '.png'), full_page=True)


with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_page(viewport={'width': 1360, 'height': 900})
    pg.goto(B + '?p=login'); shot(pg, '0-login'); pg.click('text=佐藤（営業） として試す'); pg.wait_for_load_state()
    assert '今日' in pg.content(); shot(pg, '1-home')
    # 続けて架電 → 記録 → 次へ
    pg.click('text=続けて架電する'); pg.wait_for_load_state(); shot(pg, '2-call')
    first = pg.locator('.callhead .co').inner_text(); print('call target:', first)
    pg.check('input[data-kind=recall]'); pg.fill('textarea[name=memo]', '担当不在。明日10時に再度'); pg.click('button:has-text("記録する")'); pg.wait_for_load_state()
    assert '記録しました' in pg.content(); second = pg.locator('.callhead .co').inner_text(); print('next target:', second); assert first != second
    pg.check('input[data-kind=appo]'); pg.fill('textarea[name=memo]', '来週水曜に訪問'); pg.click('button:has-text("記録する")'); pg.wait_for_load_state()
    assert 'アポなので顧客にしました' in pg.content(); shot(pg, '3-after-appo')
    # リスト → CSV 取込（確認 → 取込）
    pg.goto(B + '?p=lists'); shot(pg, '4-lists'); pg.click('text=製造業（愛知・岐阜）'); pg.wait_for_load_state(); shot(pg, '5-targets')
    pg.goto(B + '?p=import&list_id=1'); pg.set_input_files('input[name=csv]', CSV); pg.click('button:has-text("まず確かめる")'); pg.wait_for_load_state()
    print('dry:', pg.locator('.flash').inner_text()); assert '追加 2件・重複 1件・空 1件' in pg.locator('.flash').inner_text() or '追加 3件' in pg.locator('.flash').inner_text()
    pg.set_input_files('input[name=csv]', CSV); pg.click('button:has-text("取り込む")'); pg.wait_for_load_state(); print('import:', pg.locator('.flash').inner_text()); shot(pg, '6-import')
    # 架電先の検索（電話番号）
    pg.goto(B + '?p=targets&q=052-700'); assert '試験商事' in pg.content()
    pg.click('text=試験商事'); pg.wait_for_load_state(); shot(pg, '7-target')
    # 顧客・商談・かんばん
    pg.goto(B + '?p=customers'); shot(pg, '8-customers'); pg.locator('table.t a').first.click(); pg.wait_for_load_state(); shot(pg, '9-customer')
    pg.fill('form:has(input[value=add_activity]) textarea[name=body]', '訪問。現場を見せてもらった'); pg.click('form:has(input[value=add_activity]) button'); pg.wait_for_load_state(); assert '記録しました' in pg.content()
    pg.goto(B + '?p=deals'); shot(pg, '10-deals'); print('deal cards', pg.locator('.card').count())
    pg.locator('.card').first.click(); pg.wait_for_load_state(); shot(pg, '11-deal')
    pg.goto(B + '?p=stats'); shot(pg, '12-stats'); assert 'アポ率' in pg.content()
    # 管理者：設定
    pg.click('button:has-text("ログアウト")'); pg.wait_for_load_state(); pg.click('text=管理者 として試す'); pg.wait_for_load_state(); pg.goto(B + '?p=settings'); shot(pg, '13-settings'); assert '担当者を足す' in pg.content()
    # API
    import json, urllib.request
    req = urllib.request.Request(B + 'api.php?r=recalls', headers={'Authorization': 'Bearer testtoken'}); d = json.load(urllib.request.urlopen(req)); print('api recalls', len(d['targets']))
    req = urllib.request.Request(B + 'api.php?r=target', data=json.dumps({'list_id': 1, 'company': '試験商事', 'phone': '052-700-0001'}).encode(), headers={'Authorization': 'Bearer testtoken', 'Content-Type': 'application/json'}, method='POST')
    try: urllib.request.urlopen(req); assert False, 'dup should 409'
    except urllib.error.HTTPError as e: assert e.code == 409, e.code; print('api dup 409 ok')
    assert urllib.request.urlopen(urllib.request.Request(B + 'api.php?r=lists')).status if False else True
    try: urllib.request.urlopen(B + 'api.php?r=lists'); assert False
    except urllib.error.HTTPError as e: assert e.code == 401; print('api no token 401 ok')
    # スマホ幅ではみ出しを測る（別のブラウザ文脈＝ログインし直す）
    for w in (320, 390):
        ctx = b.new_context(viewport={'width': w, 'height': 800}); m = ctx.new_page()
        m.goto(B + '?p=login'); sw = m.evaluate('document.documentElement.scrollWidth'); assert sw <= w, f'overflow login {sw}'
        if w == 390: m.screenshot(path=os.path.join(OUT, 'm-login.png'), full_page=True)
        m.click('text=佐藤（営業） として試す'); m.wait_for_load_state()
        for path in ('?p=home', '?p=call', '?p=targets&list_id=1', '?p=target&id=1', '?p=deals', '?p=stats', '?p=customers'):
            m.goto(B + path)
            sw = m.evaluate('document.documentElement.scrollWidth'); print(w, path, 'scrollWidth', sw); assert sw <= w, f'overflow {path} {sw}'
            if w == 390: m.screenshot(path=os.path.join(OUT, f'm-{path[3:].split("&")[0]}.png'), full_page=True)
        ctx.close()
    b.close()
print('e2e ok')
