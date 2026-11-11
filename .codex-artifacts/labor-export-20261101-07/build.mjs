import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
import {Workbook,SpreadsheetFile} from '@oai/artifact-tool';
const base=new URL('.',import.meta.url);
const d=JSON.parse(await fs.readFile(new URL('prepared.json',base),'utf8'));
const outputDir='D:/new-erp/outputs/01a195a6-ce3f-7ed2-b4b6-a42b9104e407';
await fs.mkdir(outputDir,{recursive:true});
const wb=Workbook.create();
const main=wb.worksheets.add('报工明细');
const qty=wb.worksheets.add('工单设备数量');
const last=d.details.length+8;
const localDate=t=>new Date((t+8*3600)*1000);
const rows=d.details.map(x=>[
 x.orderNo,x.products.map((p,i)=>x.products.length>1?`${i+1}. ${p}`:p).join('\n'),
 x.quantities.length===1?x.quantities[0]:x.quantities.map((n,i)=>`${i+1}. 数量 ${n}`).join('\n'),
 x.person,x.step,x.hours,localDate(x.timestamp),x.note,String(x.worktimeId)
]);
main.getRange(`A8:I${last}`).values=[['工单号','设备及规格','工单数量','报工人员','报工环节','个人工时（小时）','报工时间','数量与工时说明','报工记录ID'],...rows];
main.getRange('A2').values=[['旧 ERP 工单报工明细']];
main.getRange('A3').values=[['报工日期：2026年11月1日至7日（UTC+8，含7日全天）']];
main.getRange('A4').values=[['个人工时合计']];
main.getRange('B4').formulas=[[`=SUM(F9:F${last})`]];
main.getRange('B4').setNumberFormat('0.00" 小时"');
main.getRange('C4').values=[[`${d.stats.records}条记录`]];
main.getRange('D4').values=[[`${d.stats.people}人`]];
main.getRange('E4').values=[[`${d.stats.orders}张工单`]];
main.getRange('A5').values=[['工单数量不是本次完工数量。多产品工单的序号一一对应；工时按整单记录，不按产品重复或均分。']];
main.getRange('A6').values=[[`原始报工总工时 ${d.stats.groupHours.toFixed(2)} 小时；个人分摊合计多0.01小时，为旧系统舍入差，保留原值。`]];
main.getRange('A7').values=[['来源：本机旧ERP jiantan_erp；个人工时记录、工单日志、工单及工单产品；按个人报工时间筛选。']];
const qlast=d.products.length+6;
qty.getRange(`A6:F${qlast}`).values=[['工单号','设备及规格（工单原文）','工单数量','原单位','设备码','工单产品ID'],...d.products.map(p=>[p.orderNo,p.productName,p.quantity,p.unit,p.deviceSN,String(p.id)])];
qty.getRange('A2').values=[['工单设备数量']];
qty.getRange('A3').values=[['范围：11月1日至7日有个人报工的94张工单，每条工单产品只列一次。']];
qty.getRange('A4').values=[['数量来自工单，不等于本周完成台数；包含设备、配件及原工单其他项目。未登记单位不推断为“台”。']];
qty.getRange('A5').values=[['来源：本机旧ERP jt_works_order_product；设备名称及数量保留工单原值。']];
function style(sheet,end,col,widths,header){
 const all=sheet.getRange(`A1:${col}${end}`);
 all.format.font={name:'Arial',size:10,color:'#243348'};
 all.format.verticalAlignment='center';all.format.rowHeight=24;
 sheet.showGridLines=false;
 sheet.getRange(`A2:${col}2`).format.font={size:16,bold:true,color:'#1E3A5F'};
 sheet.getRange(`A2:${col}2`).format.rowHeight=30;
 sheet.getRange(`A2:${col}2`).format.borders={bottom:{style:'thin',color:'#9AABC0'}};
 sheet.getRange(`A3:${col}${header-1}`).format.font={size:10,color:'#55657A'};
 const table=sheet.tables.add(`A${header}:${col}${end}`,true,sheet===main?'LaborDetails':'OrderProducts');
 table.style='TableStyleMedium2';table.showFilterButton=true;
 const h=sheet.getRange(`A${header}:${col}${header}`);
 h.format.fill='#1E3A5F';h.format.font={bold:true,color:'#FFFFFF'};
 h.format.horizontalAlignment='center';h.format.rowHeight=32;
 widths.forEach((width,i)=>sheet.getRangeByIndexes(0,i,end,1).format.columnWidth=width);
 sheet.freezePanes.freezeRows(header);
 sheet.freezePanes.freezeColumns(1);
}
style(main,last,'I',[20,82,18,12,12,23,27,38,17],8);
style(qty,qlast,'F',[20,90,16,14,25,18],6);
main.tabColor='#1E3A5F';
main.getRange(`B9:C${last}`).format.wrapText=true;
main.getRange(`B9:C${last}`).format.verticalAlignment='top';
main.getRange(`F9:F${last}`).setNumberFormat('0.00');
main.getRange(`G9:G${last}`).setNumberFormat('yyyy-mm-dd hh:mm:ss');
main.getRange(`F9:F${last}`).format.horizontalAlignment='right';
main.getRange(`G9:G${last}`).format.horizontalAlignment='center';
main.getRange(`C9:C${last}`).format.horizontalAlignment='center';
main.getRange('B4').format.horizontalAlignment='left';
main.getRange('A4:F4').format.font={bold:true,color:'#1E3A5F'};
main.getRange('A4:F4').format.rowHeight=28;
for(let i=0;i<d.details.length;i++){
 const x=d.details[i];
 const lines=x.products.reduce((s,p)=>s+Math.max(1,Math.ceil((p.length+4)/39)),0);
 main.getRange(`A${i+9}:I${i+9}`).format.rowHeight=Math.max(30,lines*17+8);
}
qty.getRange(`B7:B${qlast}`).format.wrapText=true;
qty.getRange(`C7:C${qlast}`).setNumberFormat('0');
qty.getRange(`C7:C${qlast}`).format.horizontalAlignment='right';
qty.getRange(`D7:D${qlast}`).format.horizontalAlignment='center';
for(let i=0;i<d.products.length;i++)qty.getRange(`A${i+7}:F${i+7}`).format.rowHeight=Math.max(30,Math.ceil(d.products[i].productName.length/43)*17+8);
wb.recalculate();
assert.equal(Math.round(Number(main.getRange('B4').values[0][0])*100),Math.round(d.stats.hours*100));
console.log((await wb.inspect({kind:'region',sheetId:'报工明细',range:'A4:F4',maxChars:1200})).ndjson);
const errors=await wb.inspect({kind:'match',searchTerm:'#REF!|#DIV/0!|#VALUE!|#NAME\\?|#NUM!|#SPILL!',options:{useRegex:true,maxResults:20},maxChars:1200});
console.log(errors.ndjson);
const result=await SpreadsheetFile.exportXlsx(wb);
await result.save(`${outputDir}/旧ERP工单报工明细_2026-11-01至07.xlsx`);
for(const [sheetName,range,file] of [['报工明细','A1:H13','detail-preview.png'],['工单设备数量','A1:F12','quantity-preview.png']]){
 const preview=await wb.render({sheetName,range,format:'png',scale:1.4});
 await fs.writeFile(new URL(file,base),new Uint8Array(await preview.arrayBuffer()));
}
await fs.writeFile(new URL('workbook-state.json',base),JSON.stringify({output:`${outputDir}/旧ERP工单报工明细_2026-11-01至07.xlsx`,stats:d.stats}));
console.log('EXPORT_OK');
