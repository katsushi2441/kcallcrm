#!/usr/bin/env python3
"""OGP（1200×630）とファビコン。Kurage シリーズの型（ライト・青緑・マスコット入り・価格は入れない）。  /usr/bin/python3 scripts/make_ogp.py"""
import io
import os
import urllib.request

from PIL import Image, ImageDraw, ImageFont

R = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
B = '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc'; N = '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc'
TEAL = (10, 143, 133); INK = (20, 34, 47); SUB = (60, 75, 90)
im = Image.new('RGB', (1200, 630), (244, 247, 249)); d = ImageDraw.Draw(im)
d.rectangle((0, 0, 1200, 12), fill=TEAL)
logo = Image.open(io.BytesIO(urllib.request.urlopen('https://exbridge.jp/images/logo-mark-128.png').read())).convert('RGBA').resize((48, 48))
im.paste(logo, (60, 56), logo)
d.text((120, 62), 'Kurage テレアポCRM・SFA', font=ImageFont.truetype(B, 32), fill=(7, 117, 109))
d.text((60, 140), '架電から商談まで、', font=ImageFont.truetype(B, 58), fill=INK)
d.text((60, 214), 'テレアポ営業を1つに', font=ImageFont.truetype(B, 58), fill=INK)
f = ImageFont.truetype(N, 26)
for k, t in enumerate(('架電リストをCSVで取り込み、重複は飛ばす', '「続けて架電」で結果と次回コールを残す', 'アポが取れたら顧客にして商談を追う', '担当ごとの架電数・通話数・アポ率')):
    d.text((60, 318 + k * 42), '・' + t, font=f, fill=SUB)
# 右：架電の画面の見本
x0, y0 = 690, 110
d.rounded_rectangle((x0, y0, x0 + 450, y0 + 380), radius=16, fill=(255, 255, 255), outline=(221, 230, 236), width=2)
d.text((x0 + 22, y0 + 18), '株式会社みなと工業', font=ImageFont.truetype(B, 26), fill=INK)
d.text((x0 + 22, y0 + 58), '052-123-4567', font=ImageFont.truetype(B, 30), fill=(7, 117, 109))
d.text((x0 + 22, y0 + 104), '総務部　田中 一郎　／　架電 2回　前回：不在', font=ImageFont.truetype(N, 17), fill=SUB)
chips = [('アポ獲得', (223, 243, 238), (10, 107, 99)), ('再コール', (255, 242, 214), (122, 82, 0)), ('見込み', (233, 240, 255), (42, 79, 154)), ('不在・不通', (241, 241, 241), (102, 102, 102)), ('断り', (253, 231, 228), (192, 57, 43))]
x = x0 + 22; y = y0 + 150
for t, bg, fg in chips:
    w = d.textlength(t, font=ImageFont.truetype(B, 18)) + 28
    if x + w > x0 + 430: x = x0 + 22; y += 48
    d.rounded_rectangle((x, y, x + w, y + 38), radius=19, fill=bg); d.text((x + 14, y + 7), t, font=ImageFont.truetype(B, 18), fill=fg); x += w + 10
d.rounded_rectangle((x0 + 22, y0 + 258, x0 + 428, y0 + 318), radius=10, fill=(250, 252, 252), outline=(221, 230, 236), width=2)
d.text((x0 + 34, y0 + 272), '総務の田中様。来期に検討。3月に再度', font=ImageFont.truetype(N, 18), fill=SUB)
d.rounded_rectangle((x0 + 22, y0 + 330, x0 + 220, y0 + 366), radius=10, fill=TEAL); d.text((x0 + 48, y0 + 336), '記録する → 次へ', font=ImageFont.truetype(B, 19), fill=(255, 255, 255))
m = Image.open('/home/kojima/work/kurage_web/images/kurage-mascot-cutout.png').convert('RGBA'); m = m.resize((120, int(120 * m.height / m.width)))
im.paste(m, (60, 630 - m.height - 16), m)
d.text((200, 572), '株式会社エクスブリッジ', font=ImageFont.truetype(N, 22), fill=(93, 107, 122))
im.save(os.path.join(R, 'public', 'kcc_ogp.png'), optimize=True)
# ファビコン＝マスコット（縦横比を変えない・contain）
mm = Image.open('/home/kojima/work/kurage_web/images/kurage-mascot-cutout.png').convert('RGBA')
s = 64 / max(mm.width, mm.height); mm = mm.resize((max(1, int(mm.width * s)), max(1, int(mm.height * s))), Image.LANCZOS)
fv = Image.new('RGBA', (64, 64), (0, 0, 0, 0)); fv.paste(mm, ((64 - mm.width) // 2, (64 - mm.height) // 2), mm)
fv.save(os.path.join(R, 'public', 'kcc_favicon.png')); print('ok')
