import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
import {FileBlob,SpreadsheetFile} from '@oai/artifact-tool';
const base=new URL('.',import.meta.url);
const state=JSON.parse(await fs.readFile(new URL('workbook-state.json',base),'utf8'));
const wb=await SpreadsheetFile.importXlsx(await FileBlob.load(state.output));
if(process.argv.includes('--preview')){
 const png=await wb.render({sheetName:'工单设备数量',range:'A1:F10',format:'png',scale:1});
 await fs.writeFile(new URL('before-prices.png',base),new Uint8Array(await png.arrayBuffer()));
 process.exit(0);
}
const d=JSON.parse(await fs.readFile(new URL('prepared.json',base),'utf8'));
const source=JSON.parse(await fs.readFile(new URL('source.json',base),'utf8'));
const salesNos=new Map(source.orders.map(o=>[Number(o.id),o.order_no]));
const salesNosByWorkNo=new Map(source.orders.map(o=>[o.works_no,o.order_no]));
assert(source.orders.every(o=>o.order_no),'存在未登记销售订单号的工单');
const remarks=JSON.parse(await fs.readFile(new URL('remarks.json',base),'utf8'));
const orderRemarks=new Map(remarks.orders.map(o=>[Number(o.id),o]));
const productRemarks=new Map(remarks.products.map(p=>[Number(p.id),p.remark]));
const reportRemarks=new Map(remarks.logs.map(l=>[Number(l.id),l.message]));
const nonempty=v=>v!==null && v!==undefined && String(v).trim()!=='';
function combinedRemark(id){
 const o=orderRemarks.get(id);assert(o);
 const fields=[['订单备注',o.remark],['工单订单备注',o.order_note===o.remark?null:o.order_note],['工单备注',o.line_note],['焊接工艺定制',o.remark_hanjie],['生产工艺流程',o.remark_liucheng],['仓储物流',o.remark_wuliu]];
 return fields.filter(([,v])=>nonempty(v)).map(([label,v])=>`${label}：${v}`).join('\n')||null;
}
const raw=JSON.parse(await fs.readFile(new URL('prices.json',base),'utf8'));
const freight=JSON.parse(await fs.readFile(new URL('freight.json',base),'utf8'));
const freightOrders=new Set(freight.filter(x=>Number(x.line_amount)>0).map(x=>Number(x.work_order_id)));
const prices=new Map(raw.map(p=>[Number(p.id),p]));
const orders=new Map();
for(const p of raw){if(!orders.has(p.works_order_id))orders.set(p.works_order_id,[]);orders.get(p.works_order_id).push(p);}
for(const list of orders.values())list.sort((a,b)=>a.id-b.id);
assert.equal(prices.size,d.products.length);
const main=wb.worksheets.getItem('报工明细'),qty=wb.worksheets.getItem('工单设备数量');
const last=d.details.length+8,qlast=d.products.length+6;
// 导入器会误读空共享字符串，按已核对的源值恢复设备码空白。
qty.getRange(`E7:E${qlast}`).values=d.products.map(p=>[p.deviceSN||null]);
main.getRange(`G9:G${last}`).values=d.details.map(x=>[(x.timestamp+8*3600)/86400+25569]);
main.getRange(`G9:G${last}`).setNumberFormat('yyyy-mm-dd hh:mm:ss');
main.getRange(`I9:I${last}`).values=d.details.map(x=>[String(x.worktimeId)]);
qty.getRange(`F7:F${qlast}`).values=d.products.map(p=>[String(p.id)]);
for(let i=0;i<d.details.length;i++){
 assert.equal(main.getRange(`F${i+9}`).values[0][0],d.details[i].hours);
 assert.equal(main.getRange(`D${i+9}`).values[0][0],d.details[i].person);
}
main.getRange(`J8:J${last}`).copyFrom(main.getRange(`I8:I${last}`),'all');
main.getRange(`J8:J${last}`).values=[['工单产品单价（元）'],...d.details.map(x=>{
 const p=orders.get(x.orderId);assert.equal(p.length,x.products.length);
 p.forEach((v,i)=>assert.equal(v.name,x.products[i]));
 return [p.length===1?(p[0].price===null?'未登记':Number(p[0].price)):p.map((v,i)=>`${i+1}. ${v.price===null?'未登记':Number(v.price).toFixed(2)}`).join('\n')];
})];
main.getRange(`J9:J${last}`).setNumberFormat('#,##0.00');
main.getRange(`J9:J${last}`).format.wrapText=true;
main.getRange(`J9:J${last}`).format.verticalAlignment='top';
main.getRange(`J9:J${last}`).format.horizontalAlignment='right';
main.getRange(`J1:J${last}`).format.columnWidth=26;
qty.getRange(`G6:G${qlast}`).copyFrom(qty.getRange(`F6:F${qlast}`),'all');
qty.getRange(`G6:G${qlast}`).values=[['工单产品单价（元）'],...d.products.map(p=>{const price=prices.get(Number(p.id)).price;return [price===null?'未登记':Number(price)];})];
qty.getRange(`G7:G${qlast}`).setNumberFormat('#,##0.00');
qty.getRange(`G7:G${qlast}`).format.horizontalAlignment='right';
qty.getRange(`G1:G${qlast}`).format.columnWidth=26;
main.getRange(`K8:K${last}`).copyFrom(main.getRange(`I8:I${last}`),'all');
main.getRange(`L8:L${last}`).copyFrom(main.getRange(`I8:I${last}`),'all');
main.getRange(`K8:L${last}`).values=[['运费标注','多人报工标注'],...d.details.map(x=>[
 freightOrders.has(x.orderId)?'含运费':null,
 x.team.length===1?null:`${x.team.length===2?'双人':x.team.length+'人'}报工（${x.team.join('、')}）`
])];
main.getRange(`K1:K${last}`).format.columnWidth=15;
main.getRange(`K9:K${last}`).format.horizontalAlignment='center';
main.getRange(`L1:L${last}`).format.columnWidth=48;
main.getRange(`L9:L${last}`).format.wrapText=true;
qty.getRange(`H6:H${qlast}`).copyFrom(qty.getRange(`F6:F${qlast}`),'all');
qty.getRange(`H6:H${qlast}`).values=[['订单运费标注'],...d.products.map(p=>[freightOrders.has(Number(prices.get(Number(p.id)).works_order_id))?'含运费':null])];
qty.getRange(`H1:H${qlast}`).format.columnWidth=20;
qty.getRange(`H7:H${qlast}`).format.horizontalAlignment='center';
main.getRange(`M8:M${last}`).copyFrom(main.getRange(`I8:I${last}`),'all');
main.getRange(`M8:M${last}`).values=[['销售订单号'],...d.details.map(x=>[salesNos.get(x.orderId)])];
main.getRange(`M1:M${last}`).format.columnWidth=23;
main.getRange(`M9:M${last}`).format.horizontalAlignment='left';
qty.getRange(`I6:I${qlast}`).copyFrom(qty.getRange(`F6:F${qlast}`),'all');
qty.getRange(`I6:I${qlast}`).values=[['销售订单号'],...d.products.map(x=>[salesNosByWorkNo.get(x.orderNo)])];
qty.getRange(`I1:I${qlast}`).format.columnWidth=23;
qty.getRange(`I7:I${qlast}`).format.horizontalAlignment='left';
const notes=d.details.map(x=>[combinedRemark(x.orderId),nonempty(reportRemarks.get(x.logId))?reportRemarks.get(x.logId):null]);
main.getRange(`N8:N${last}`).copyFrom(main.getRange(`I8:I${last}`),'all');
main.getRange(`O8:O${last}`).copyFrom(main.getRange(`I8:I${last}`),'all');
main.getRange(`N8:O${last}`).values=[['订单/工单备注','报工备注'],...notes];
main.getRange(`N1:N${last}`).format.columnWidth=90;
main.getRange(`O1:O${last}`).format.columnWidth=65;
main.getRange(`N9:O${last}`).format.wrapText=true;
main.getRange(`N9:O${last}`).format.horizontalAlignment='left';
main.getRange(`N9:O${last}`).format.verticalAlignment='top';
qty.getRange(`J6:J${qlast}`).copyFrom(qty.getRange(`F6:F${qlast}`),'all');
const productNotes=d.products.map(p=>[nonempty(productRemarks.get(Number(p.id)))?productRemarks.get(Number(p.id)):null]);
qty.getRange(`J6:J${qlast}`).values=[['产品备注'],...productNotes];
qty.getRange(`J1:J${qlast}`).format.columnWidth=75;
qty.getRange(`J7:J${qlast}`).format.wrapText=true;
qty.getRange(`J7:J${qlast}`).format.horizontalAlignment='left';
qty.getRange(`J7:J${qlast}`).format.verticalAlignment='top';
const lineCount=(v,width)=>String(v||'').split(/\r?\n/).reduce((n,line)=>n+Math.max(1,Math.ceil(line.length/width)),0);
notes.forEach(([order,report],i)=>{
 const row=main.getRange(`A${i+9}:O${i+9}`);
 row.format.rowHeight=Math.max(Number(main.getRange(`A${i+9}`).format.rowHeight)||30,lineCount(order,44)*17+8,lineCount(report,32)*17+8);
});
productNotes.forEach(([note],i)=>{
 const row=qty.getRange(`A${i+7}:J${i+7}`);
 row.format.rowHeight=Math.max(Number(qty.getRange(`A${i+7}`).format.rowHeight)||30,lineCount(note,37)*17+8);
});
for(const [sheet,range,name] of [[main,`A8:O${last}`,'LaborDetails'],[qty,`A6:J${qlast}`,'OrderProducts']]){
 sheet.tables.items[0].delete();
 const t=sheet.tables.add(range,true,name);t.style='TableStyleMedium2';t.showFilterButton=true;
}
qty.getRange('A5').values=[['来源：本机旧ERP工单产品记录；单价为生成工单时从销售订单带入的产品单价，不是人工单价。']];
main.getRange('A7').values=[['来源：本机旧ERP。产品单价按设备序号对应；含运费指关联订单有正金额运费录入；多人保留个人分摊工时。']];
wb.recalculate();
assert.equal(Math.round(Number(main.getRange('B4').values[0][0])*100),24167);
const vals=qty.getRange(`G7:G${qlast}`).values;
d.products.forEach((p,i)=>assert.equal(vals[i][0],prices.get(Number(p.id)).price===null?'未登记':Number(prices.get(Number(p.id)).price)));
console.log((await wb.inspect({kind:'region',sheetId:'工单设备数量',range:'F6:G10',maxChars:1600})).ndjson);
console.log((await wb.inspect({kind:'match',searchTerm:'#REF!|#DIV/0!|#VALUE!|#NAME\\?',options:{useRegex:true,maxResults:20},maxChars:1000})).ndjson);
let output=state.output;
const exported=await SpreadsheetFile.exportXlsx(wb);
try{await exported.save(output);}catch(error){
 if(error.code!=='EBUSY')throw error;
 output=state.output.replace(/(?:_含备注)?\.xlsx$/,'_含备注.xlsx');
 await exported.save(output);
}
await fs.writeFile(new URL('workbook-state.json',base),JSON.stringify({...state,output}));
const teamRow=d.details.findIndex(x=>x.team.length>1)+9;
const reportRow=notes.findIndex(x=>nonempty(x[1]))+9;
const productRow=productNotes.findIndex(x=>nonempty(x[0]))+7;
for(const [sheetName,range,file] of [['报工明细','M8:O13','remarks-detail.png'],['报工明细',`M${reportRow}:O${reportRow+1}`,'remarks-report.png'],['工单设备数量',`I${productRow}:J${productRow+1}`,'remarks-product.png']]){
 const img=await wb.render({sheetName,range,format:'png',scale:1.2});
 await fs.writeFile(new URL(file,base),new Uint8Array(await img.arrayBuffer()));
}
console.log('PRICES_ADDED: '+raw.length);
console.log(JSON.stringify({freightOrders:freightOrders.size,doubleRows:d.details.filter(x=>x.team.length===2).length,tripleRows:d.details.filter(x=>x.team.length===3).length}));
