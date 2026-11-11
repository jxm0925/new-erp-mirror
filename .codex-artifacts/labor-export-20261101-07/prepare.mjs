import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
const dir = new URL('.',import.meta.url);
const d = JSON.parse(await fs.readFile(new URL('source.json',dir),'utf8'));
const steps = {wait:'准备中',cutting:'下料',welding:'焊接',polish:'打磨',assemble:'总装',testing:'试水',packaging:'打包',sending:'发货',complete:'完成'};
const orders = new Map(d.orders.map(x=>[x.id,x]));
const groups = new Map();
for(const p of d.products){if(!groups.has(p.works_order_id))groups.set(p.works_order_id,[]);groups.get(p.works_order_id).push(p);}
const cents=x=>Math.round(Number(x)*100);
assert.equal(d.worktimes.length,Number(d.control.records));
assert.equal(new Set(d.worktimes.map(x=>x.id)).size,d.worktimes.length);
assert.equal(d.worktimes.reduce((s,x)=>s+cents(x.hours),0),cents(d.control.hours));
const usedLogs=new Map();
let late=0;
for(const w of d.worktimes){
  assert(w.createtime>=d.start && w.createtime<d.end_exclusive);
  assert(orders.has(w.order_id));assert(groups.has(w.order_id));assert(steps[w.step]);assert(w.nickname);
  assert(!w.content.includes('设备编号'),'Unexpected product-specific report');
  const candidates=d.logs.filter(l=>!l.work_product_id && l.order_id===w.order_id && String(l.admin_id).split(',').includes(String(w.admin_id)) && l.content.includes('已完成工序'+steps[w.step]));
  let matches=candidates.filter(l=>l.createtime===w.createtime);
  if(!matches.length){matches=candidates.filter(l=>l.createtime===w.createtime-1);late++;}
  assert.equal(matches.length,1,`Ambiguous/missing log for ${w.id}`);
  const log=matches[0];
  if(!usedLogs.has(log.id))usedLogs.set(log.id,{log,allocations:[]});
  usedLogs.get(log.id).allocations.push(w);
}
for(const {log,allocations} of usedLogs.values()){
  assert.deepEqual(allocations.map(x=>x.admin_id).sort((a,b)=>a-b),String(log.admin_id).split(',').map(Number).sort((a,b)=>a-b));
}
const logHours=[...usedLogs.values()].reduce((s,x)=>s+cents(x.log.hours),0)/100;
const sourcePositive=d.logs.filter(x=>Number(x.hours)>0 && x.createtime>=d.start && x.createtime<d.end_exclusive);
assert.equal(sourcePositive.length,usedLogs.size);
const details=d.worktimes.map(w=>{
 const order=orders.get(w.order_id),p=groups.get(w.order_id);
 const matched=[...usedLogs.values()].find(x=>x.allocations.some(a=>a.id===w.id));
 const team=matched.allocations;
 return {worktimeId:w.id,logId:matched.log.id,orderId:w.order_id,orderNo:order.works_no||String(order.id),products:p.map(x=>x.name),quantities:p.map(x=>x.amount),person:w.nickname,step:steps[w.step],hours:Number(w.hours),timestamp:w.createtime,note:p.length>1?'整单报工，未拆分到各产品':'工单数量，不代表本次完工数量',team:team.map(a=>a.nickname)};
});
const uniqueOrderIds=new Set(d.worktimes.map(x=>x.order_id));
const products=d.products.filter(p=>uniqueOrderIds.has(p.works_order_id)).map(p=>({orderNo:orders.get(p.works_order_id).works_no,productName:p.name,quantity:p.amount,unit:p.measureunit||'未登记',deviceSN:p.device_sn||'',id:p.id}));
const result={details,products,stats:{records:details.length,people:Number(d.control.people),orders:uniqueOrderIds.size,hours:Number(d.control.hours),groupHours:logHours,logs:usedLogs.size,lateOneSecond:late,multiProductOrders:[...groups.values()].filter(x=>x.length>1).length},captured_at:d.captured_at};
await fs.writeFile(new URL('prepared.json',dir),JSON.stringify(result,null,2));
console.log(JSON.stringify(result.stats));
