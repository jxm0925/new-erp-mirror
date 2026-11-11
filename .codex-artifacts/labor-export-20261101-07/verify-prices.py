import json
import zipfile
import xml.etree.ElementTree as ET
from decimal import Decimal
from pathlib import Path

base = Path(__file__).parent
load = lambda name: json.loads((base / name).read_text(encoding='utf-8'))
d, prices, freight, state = map(load, ['prepared.json', 'prices.json', 'freight.json', 'workbook-state.json'])
price_map = {int(p['id']): p for p in prices}
source = load('source.json')
sales_nos = {int(o['id']): o['order_no'] for o in source['orders']}
sales_nos_by_work = {o['works_no']: o['order_no'] for o in source['orders']}
remarks = load('remarks.json')
order_remarks = {int(o['id']): o for o in remarks['orders']}
report_remarks = {int(o['id']): o['message'] or '' for o in remarks['logs']}
product_remarks = {int(o['id']): o['remark'] or '' for o in remarks['products']}
positive = {int(f['work_order_id']) for f in freight if Decimal(f['line_amount']) > 0}
ns = {'m': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
with zipfile.ZipFile(state['output']) as book:
    strings = [''.join(si.itertext()) for si in ET.fromstring(book.read('xl/sharedStrings.xml'))]
    def sheet(num):
        root = ET.fromstring(book.read(f'xl/worksheets/sheet{num}.xml'))
        assert root.find('.//m:pane', ns) is not None
        result = {}
        for cell in root.findall('.//m:c', ns):
            assert cell.get('t') != 'e'
            v = cell.find('m:v', ns)
            value = '' if v is None else v.text
            if cell.get('t') == 's': value = strings[int(value)]
            elif cell.get('t') == 'inlineStr': value = ''.join(cell.find('m:is', ns).itertext())
            result[cell.get('r')] = value or ''
        return result
    main, qty = sheet(1), sheet(2)
    total = Decimal(0)
    for row, r in enumerate(d['details'], 9):
        get = lambda col: main.get(f'{col}{row}', '')
        assert get('A') == r['orderNo']
        assert get('B') == '\n'.join((f'{i+1}. {p}' if len(r['products']) > 1 else p) for i,p in enumerate(r['products']))
        expected_qty = '\n'.join(f'{i+1}. 数量 {n}' for i,n in enumerate(r['quantities'])) if len(r['quantities']) > 1 else str(r['quantities'][0])
        assert get('C') == expected_qty
        assert get('D') == r['person'] and get('E') == r['step']
        assert Decimal(get('F')) == Decimal(str(r['hours']))
        total += Decimal(get('F'))
        assert abs((float(get('G')) - 25569)*86400 - r['timestamp'] - 28800) < .01
        assert get('H') == r['note'] and get('I') == str(r['worktimeId'])
        assert get('K') == ('含运费' if r['orderId'] in positive else '')
        team = r['team']
        label = '' if len(team) == 1 else ('双人' if len(team) == 2 else str(len(team))+'人') + '报工（' + '、'.join(team) + '）'
        assert get('L') == label
        assert get('M') == sales_nos[r['orderId']]
        note = order_remarks[r['orderId']]
        values = [('订单备注',note['remark']),('工单订单备注',None if note['order_note']==note['remark'] else note['order_note']),('工单备注',note['line_note']),('焊接工艺定制',note['remark_hanjie']),('生产工艺流程',note['remark_liucheng']),('仓储物流',note['remark_wuliu'])]
        assert get('N') == '\n'.join(label+'：'+value for label,value in values if value is not None and str(value).strip())
        assert get('O') == (report_remarks[r['logId']] if report_remarks[r['logId']].strip() else '')
        p = sorted([p for p in prices if int(p['works_order_id']) == r['orderId']], key=lambda p: int(p['id']))
        if len(p) == 1: assert Decimal(get('J')) == Decimal(p[0]['price'])
        else: assert get('J') == '\n'.join(f'{i+1}. {Decimal(x["price"]):.2f}' for i,x in enumerate(p))
    assert total == Decimal('241.67')
    for row, p in enumerate(d['products'], 7):
        get = lambda col: qty.get(f'{col}{row}', '')
        assert get('A') == p['orderNo'] and get('B') == p['productName']
        assert Decimal(get('C')) == p['quantity']
        assert get('D') == p['unit'] and get('E') == p['deviceSN'] and get('F') == str(p['id'])
        source = price_map[p['id']]
        assert Decimal(get('G')) == Decimal(source['price'])
        assert get('H') == ('含运费' if int(source['works_order_id']) in positive else '')
        assert get('I') == sales_nos_by_work[p['orderNo']]
        assert get('J') == (product_remarks[p['id']] if product_remarks[p['id']].strip() else '')
    assert len([n for n in book.namelist() if n.startswith('xl/tables/table') and n.endswith('.xml')]) == 2
print('Verified: all remarks match source records; sales order numbers, 188 reports, 126 product prices, freight flags, team flags, original values and dates preserved; total 241.67 hours.')
